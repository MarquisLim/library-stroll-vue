"""Serve the frozen 4.5A corpus through a local-only research Rerank API."""

import argparse
import hashlib
import logging
import time
from contextlib import asynccontextmanager
from dataclasses import dataclass
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


Hash = Annotated[str, StringConstraints(pattern=r"^[0-9a-f]{64}$")]
Version = Annotated[str, StringConstraints(pattern=r"^[0-9a-f]{32}$")]
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


@dataclass(frozen=True)
class DocumentRevision:
    content_hash: str | None
    version: str | None


ABSENT = DocumentRevision(None, None)


def content_hash(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def require_loopback(request: Request) -> None:
    if not request.client or request.client.host != "127.0.0.1" or request.url.hostname not in ("127.0.0.1", "localhost"):
        raise HTTPException(status_code=403, detail="Document operations require local loopback")


def add_document_routes(app: FastAPI, inference_lock: Lock) -> None:
    router = APIRouter(prefix="/api/v1/documents", dependencies=[Depends(require_loopback)])

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
            if current != DocumentRevision(body.expected_previous_hash, body.expected_previous_version):
                raise HTTPException(status_code=409, detail="Document version conflict")

            started = time.perf_counter()
            vectors = encode_documents(app.state.model, {artwork_id: body.searchable_text}, [artwork_id])
            app.state.synchronize()
            elapsed_ms = (time.perf_counter() - started) * 1_000
            app.state.document_vectors[artwork_id] = vectors[artwork_id]
            app.state.document_revisions[artwork_id] = DocumentRevision(digest, uuid4().hex)
            app.state.encoded_documents += 1
            status = "created" if current.content_hash is None else "updated"
            return {**info(artwork_id), "status": status, "encoded_documents": 1, "encoding_ms": elapsed_ms}

    @router.delete("/{artwork_id}")
    def delete_document(artwork_id: ArtworkId, expected_previous_hash: Hash, expected_previous_version: Version):
        with inference_lock:
            current = app.state.document_revisions.get(artwork_id, ABSENT)
            if current.content_hash is None:
                return {**info(artwork_id), "status": "absent", "encoded_documents": 0}
            if current != DocumentRevision(expected_previous_hash, expected_previous_version):
                raise HTTPException(status_code=409, detail="Document version conflict")
            del app.state.document_vectors[artwork_id]
            # Keep a version for the deleted ID so an old create cannot resurrect it.
            app.state.document_revisions[artwork_id] = DocumentRevision(None, uuid4().hex)
            return {**info(artwork_id), "status": "deleted", "encoded_documents": 0}

    app.include_router(router)


def create_app(directory: Path, model_path: Path, device: str, *, dynamic_documents: bool = False) -> FastAPI:
    inference_lock = Lock()

    @asynccontextmanager
    async def lifespan(app):
        started = time.perf_counter()
        documents, _, candidates = load_inputs(directory)
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
        synchronize()
        ready = time.perf_counter()
        logger.info("ColBERT documents prepared: %d", len(document_vectors))

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
        if dynamic_documents:
            app.state.document_revisions = {
                artwork_id: DocumentRevision(content_hash(text), uuid4().hex)
                for artwork_id, text in documents.items()
            }
            app.state.encoded_documents = len(documents)
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
    args = parser.parse_args()
    uvicorn.run(
        create_app(args.directory, args.model, args.device, dynamic_documents=args.dynamic_documents),
        host="0.0.0.0" if args.container else "127.0.0.1",
        port=args.port,
        workers=1,
    )


if __name__ == "__main__":
    main()
