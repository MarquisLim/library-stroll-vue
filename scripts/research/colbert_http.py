"""Serve the frozen 4.5A corpus through a local-only research Rerank API."""

import argparse
import logging
import time
from contextlib import asynccontextmanager, nullcontext
from pathlib import Path
from threading import Lock
from typing import Annotated
from uuid import uuid4

import uvicorn
from fastapi import APIRouter, Depends, FastAPI, HTTPException, Path as PathParam, Request
from pydantic import BaseModel, Field, StrictInt, StringConstraints, field_validator

from app.schemas.requests import RerankRequest
from app.schemas.responses import RerankResponse, SearchResultItem
from colbert_rerank import (
    CANDIDATE_LIMIT,
    MODEL_ID,
    MODEL_REVISION,
    encode_documents,
    load_inputs,
    load_model,
    prepare_device,
    rerank,
    runtime_memory,
)
from colbert_state import ABSENT, DocumentRevision, DocumentState, Hash, Version, content_hash


logger = logging.getLogger("uvicorn.error")


class LocalRerankRequest(RerankRequest):
    query: Annotated[str, StringConstraints(strip_whitespace=True, min_length=1, max_length=1000)]
    artwork_ids: list[StrictInt] = Field(min_length=1, max_length=CANDIDATE_LIMIT)

    @field_validator("artwork_ids")
    @classmethod
    def require_unique_ids(cls, artwork_ids):
        if len(set(artwork_ids)) != len(artwork_ids):
            raise ValueError("Candidate IDs must be unique")
        return artwork_ids


ArtworkId = Annotated[int, PathParam(gt=0)]


class DocumentUpsert(BaseModel):
    searchable_text: Annotated[str, StringConstraints(min_length=1, max_length=50_000)]
    expected_previous_hash: Hash | None
    expected_previous_version: Version | None

    @field_validator("searchable_text")
    @classmethod
    def require_text(cls, text: str) -> str:
        if not text.strip():
            raise ValueError("searchable_text must contain non-whitespace text")
        return text


def require_loopback(request: Request) -> None:
    if not request.client or request.client.host != "127.0.0.1" or request.url.hostname not in ("127.0.0.1", "localhost"):
        raise HTTPException(status_code=403, detail="Document operations require local loopback")


def add_document_routes(app: FastAPI, inference_lock: Lock) -> None:
    router = APIRouter(prefix="/api/v1/documents", dependencies=[Depends(require_loopback)])

    def save_revision(artwork_id: int, revision: DocumentRevision) -> None:
        revisions = app.state.document_revisions
        if store := app.state.document_store:
            try:
                store.save(revisions | {artwork_id: revision})
            except OSError as error:
                logger.error("ColBERT state write failed: %s", error)
                raise HTTPException(status_code=503, detail="Cannot save ColBERT document state") from error
        revisions[artwork_id] = revision

    def info(artwork_id: int) -> dict:
        revision = app.state.document_revisions.get(artwork_id, ABSENT)
        vector = app.state.document_vectors.get(artwork_id)
        return {
            "artwork_id": artwork_id,
            "exists": vector is not None,
            "content_hash": revision.content_hash,
            "version": revision.version,
            "token_count": int(vector.shape[0]) if vector is not None else None,
        }

    @router.get("/{artwork_id}")
    def get_document(artwork_id: ArtworkId):
        with inference_lock:
            return info(artwork_id)

    @router.put("/{artwork_id}")
    def upsert_document(artwork_id: ArtworkId, body: DocumentUpsert):
        digest = content_hash(body.searchable_text)
        with inference_lock:
            current = app.state.document_revisions.get(artwork_id, ABSENT)
            if current.content_hash == digest:
                return {**info(artwork_id), "status": "unchanged", "encoded_documents": 0, "encoding_ms": 0}
            if not current.matches(body.expected_previous_hash, body.expected_previous_version):
                raise HTTPException(status_code=409, detail="Document version conflict")

            started = time.perf_counter()
            vector = encode_documents(app.state.model, {artwork_id: body.searchable_text}, [artwork_id])[artwork_id]
            app.state.synchronize()
            elapsed_ms = (time.perf_counter() - started) * 1_000
            store = app.state.document_store
            text = body.searchable_text if store and store.base_hashes.get(artwork_id) != digest else None
            save_revision(artwork_id, DocumentRevision(content_hash=digest, version=uuid4().hex, searchable_text=text))
            app.state.document_vectors[artwork_id] = vector
            app.state.encoded_documents += 1
            status = "created" if current.content_hash is None else "updated"
            return {**info(artwork_id), "status": status, "encoded_documents": 1, "encoding_ms": elapsed_ms}

    @router.delete("/{artwork_id}")
    def delete_document(artwork_id: ArtworkId, expected_previous_hash: Hash, expected_previous_version: Version):
        with inference_lock:
            current = app.state.document_revisions.get(artwork_id, ABSENT)
            if current.content_hash is None:
                return {**info(artwork_id), "status": "absent", "encoded_documents": 0}
            if not current.matches(expected_previous_hash, expected_previous_version):
                raise HTTPException(status_code=409, detail="Document version conflict")
            # Keep a version for the deleted ID so an old create cannot resurrect it.
            save_revision(artwork_id, DocumentRevision(content_hash=None, version=uuid4().hex))
            del app.state.document_vectors[artwork_id]
            return {**info(artwork_id), "status": "deleted", "encoded_documents": 0}

    app.include_router(router)


def create_app(
    directory: Path, model_path: Path, device: str, *, dynamic_documents: bool = False, state_dir: Path | None = None
) -> FastAPI:
    if state_dir is not None and not dynamic_documents:
        raise ValueError("state_dir requires dynamic_documents")
    inference_lock = Lock()

    @asynccontextmanager
    async def lifespan(app):
        started = time.perf_counter()
        documents, _, candidates = load_inputs(directory)
        base_hashes = {artwork_id: content_hash(text) for artwork_id, text in documents.items()} if dynamic_documents else {}
        store = DocumentState(state_dir, base_hashes) if state_dir is not None else None
        with store.exclusive() if store else nullcontext():
            revisions = store.load() if store else None
            synchronize = prepare_device(device)
            model, model_load_seconds = load_model(model_path, device, synchronize)
            logger.info("ColBERT model loaded")

            # Keep the benchmark's batch composition for the original 99 candidates.
            candidate_ids = sorted({hit["id"] for row in candidates for hit in row["results"]})
            remaining_ids = sorted(documents.keys() - set(candidate_ids))
            encoding_started = time.perf_counter()
            document_vectors = encode_documents(model, documents, candidate_ids)
            if remaining_ids:
                document_vectors.update(encode_documents(model, documents, remaining_ids))
            encoded_documents = len(documents)
            if revisions is not None:
                for artwork_id, revision in revisions.items():
                    if revision.content_hash is None:
                        document_vectors.pop(artwork_id, None)
                    elif revision.searchable_text is not None:
                        texts = {artwork_id: revision.searchable_text}
                        document_vectors.update(encode_documents(model, texts, [artwork_id]))
                        encoded_documents += 1
            synchronize()
            ready = time.perf_counter()
            logger.info("ColBERT documents prepared: %d", len(document_vectors))

            if dynamic_documents:
                if revisions is None:
                    revisions = {
                        artwork_id: DocumentRevision(content_hash=digest, version=uuid4().hex)
                        for artwork_id, digest in base_hashes.items()
                    }
                    if store:
                        store.save(revisions)
                app.state.document_store = store
                app.state.document_revisions = revisions
                app.state.encoded_documents = encoded_documents
            app.state.model = model
            app.state.document_vectors = document_vectors
            app.state.synchronize = synchronize
            app.state.startup = {
                "status": "ready",
                "startup_id": uuid4().hex,
                "model": MODEL_ID,
                "revision": MODEL_REVISION,
                "document_count": len(document_vectors),
                "model_load_seconds": model_load_seconds,
                "document_seconds": ready - encoding_started,
                "startup_seconds": ready - started,
            }
            yield

    app = FastAPI(title="Library Stroll ColBERT research prototype", lifespan=lifespan)

    @app.get("/ready")
    def readiness():
        if dynamic_documents:
            with inference_lock:
                return {
                    **app.state.startup,
                    "document_count": len(app.state.document_vectors),
                    "dynamic_documents": True,
                    "encoded_documents_total": app.state.encoded_documents,
                    "memory": runtime_memory(device == "cuda"),
                }
        return {**app.state.startup, "memory": runtime_memory(device == "cuda")}

    @app.post("/api/v1/rerank", response_model=RerankResponse)
    def rerank_candidates(body: LocalRerankRequest) -> RerankResponse:
        with inference_lock:
            documents = app.state.document_vectors
            if any(artwork_id not in documents for artwork_id in body.artwork_ids):
                detail = "Candidate ID is outside the corpus" if dynamic_documents else "Candidate ID is outside the frozen corpus"
                raise HTTPException(status_code=422, detail=detail)
            hits = [{"id": artwork_id} for artwork_id in body.artwork_ids]
            ranked, elapsed_ms, _ = rerank(
                app.state.model, body.query, hits, documents, app.state.synchronize
            )
        return RerankResponse(
            results=[
                SearchResultItem(artwork_id=hit["id"], score=hit["colbert_score"], rank=hit["colbert_rank"])
                for hit in ranked[:body.limit]
            ],
            reranked=True,
            model=MODEL_ID,
            latency_ms=round(elapsed_ms),
        )

    if dynamic_documents:
        add_document_routes(app, inference_lock)
    return app


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", type=Path)
    parser.add_argument("--model", type=Path, required=True)
    parser.add_argument("--device", choices=("cuda", "cpu"), default="cuda")
    parser.add_argument("--port", type=int, default=8001)
    binding = parser.add_mutually_exclusive_group()
    binding.add_argument(
        "--container", action="store_true", help="Bind inside a container published to host loopback only"
    )
    binding.add_argument("--dynamic-documents", action="store_true", help="Enable document mutations on local loopback")
    parser.add_argument("--state-dir", type=Path, help="Persist document revisions in this local directory")
    args = parser.parse_args()
    if args.state_dir is not None and not args.dynamic_documents:
        parser.error("--state-dir requires --dynamic-documents")
    uvicorn.run(
        create_app(args.directory, args.model, args.device, dynamic_documents=args.dynamic_documents, state_dir=args.state_dir),
        host="0.0.0.0" if args.container else "127.0.0.1",
        port=args.port,
        workers=1,
    )


if __name__ == "__main__":
    main()
