"""Serve the frozen 4.5A corpus through a local-only research Rerank API."""

import argparse
import logging
import time
from contextlib import asynccontextmanager
from pathlib import Path
from threading import Lock
from typing import Annotated
from uuid import uuid4

import uvicorn
from fastapi import FastAPI, HTTPException
from pydantic import Field, StrictInt, StringConstraints, field_validator

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


def create_app(directory: Path, model_path: Path, device: str) -> FastAPI:
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
        yield

    app = FastAPI(title="Library Stroll ColBERT research prototype", lifespan=lifespan)

    @app.get("/ready")
    def readiness():
        return {**app.state.startup, "memory": runtime_memory(device == "cuda")}

    @app.post("/api/v1/rerank", response_model=RerankResponse)
    def rerank_candidates(body: LocalRerankRequest) -> RerankResponse:
        documents = app.state.document_vectors
        if any(artwork_id not in documents for artwork_id in body.artwork_ids):
            raise HTTPException(status_code=422, detail="Candidate ID is outside the frozen corpus")

        hits = [{"id": artwork_id} for artwork_id in body.artwork_ids]
        with inference_lock:
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

    return app


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", type=Path)
    parser.add_argument("--model", type=Path, required=True)
    parser.add_argument("--device", choices=("cuda", "cpu"), default="cuda")
    parser.add_argument("--port", type=int, default=8001)
    parser.add_argument(
        "--container", action="store_true", help="Bind inside a container published to host loopback only"
    )
    args = parser.parse_args()
    uvicorn.run(
        create_app(args.directory, args.model, args.device),
        host="0.0.0.0" if args.container else "127.0.0.1",
        port=args.port,
        workers=1,
    )


if __name__ == "__main__":
    main()
