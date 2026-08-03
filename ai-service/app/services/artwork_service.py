import hashlib
import logging
import time

from app.clients.ollama import OllamaClient
from app.clients.qdrant import QdrantService
from app.config import settings
from app.schemas.metadata import ArtworkMetadata

logger = logging.getLogger(__name__)


class ArtworkService:
    def __init__(self, ollama: OllamaClient, qdrant: QdrantService):
        self.ollama = ollama
        self.qdrant = qdrant

    async def analyze(
        self,
        artwork_id: int,
        title: str = "",
        description: str = "",
        existing_tags: list[str] | None = None,
        image_bytes: bytes | None = None,
    ) -> dict:
        start = time.monotonic()

        if image_bytes:
            metadata = await self.ollama.analyze_image(
                image_bytes=image_bytes,
                title=title,
                description=description,
                existing_tags=existing_tags,
            )
            vision_model = settings.ollama_vision_model
        else:
            metadata = await self.ollama.analyze_text(
                title=title,
                description=description,
                existing_tags=existing_tags,
            )
            vision_model = ""

        latency_ms = int((time.monotonic() - start) * 1000)

        meta_dict = metadata.model_dump() if hasattr(metadata, "model_dump") else dict(metadata)

        return {
            "artwork_id": artwork_id,
            **meta_dict,
            "chat_model": settings.ollama_chat_model,
            "vision_model": vision_model,
            "embedding_model": settings.ollama_embedding_model,
            "latency_ms": latency_ms,
        }

    async def index(
        self,
        artwork_id: int,
        metadata: dict,
        searchable_text: str,
        payload: dict,
        content_hash: str = "",
        idempotency_key: str = "",
    ) -> dict:
        vector = await self.ollama.embed(searchable_text)

        point_id = idempotency_key or None
        qdrant_point_id = await self.qdrant.upsert(
            artwork_id=artwork_id,
            vector=vector,
            payload={
                **payload,
                "content_hash": content_hash,
                "schema_version": settings.metadata_schema_version,
                "prompt_version": settings.prompt_version,
            },
            point_id=point_id,
        )

        return {
            "artwork_id": artwork_id,
            "qdrant_point_id": qdrant_point_id,
            "status": "indexed",
        }

    async def delete_index(self, artwork_id: int) -> dict:
        await self.qdrant.delete(artwork_id)
        return {
            "artwork_id": artwork_id,
            "status": "deleted",
        }

    async def search(
        self,
        query: str,
        limit: int = 20,
        filters: dict | None = None,
    ) -> dict:
        start = time.monotonic()

        vector = await self.ollama.embed(query)
        results = await self.qdrant.search(vector=vector, limit=limit, filters=filters)

        for rank, item in enumerate(results, 1):
            item["rank"] = rank

        latency_ms = int((time.monotonic() - start) * 1000)

        return {
            "results": results,
            "model": settings.ollama_embedding_model,
            "latency_ms": latency_ms,
        }

    async def similar(
        self,
        artwork_id: int,
        limit: int = 20,
        filters: dict | None = None,
    ) -> dict:
        start = time.monotonic()

        results = await self.qdrant.similar(
            artwork_id=artwork_id, limit=limit, filters=filters
        )

        for rank, item in enumerate(results, 1):
            item["rank"] = rank

        latency_ms = int((time.monotonic() - start) * 1000)

        return {
            "results": results,
            "model": settings.ollama_embedding_model,
            "latency_ms": latency_ms,
        }

    async def embed(self, text: str) -> dict:
        vector = await self.ollama.embed(text)
        return {
            "model": settings.ollama_embedding_model,
            "dimensions": len(vector),
            "embedding": vector,
        }

    async def ensure_collection(self) -> None:
        dimension = await self.ollama.get_embedding_dimension()
        await self.qdrant.ensure_collection(dimension)
