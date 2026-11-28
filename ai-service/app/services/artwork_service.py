import asyncio
import logging
import time

import httpx

from app.clients.artwork_visibility import ArtworkVisibilityClient, is_artwork_id
from app.clients.ollama import OllamaClient, VisionOutputError
from app.clients.qdrant import QdrantService
from app.config import settings

logger = logging.getLogger(__name__)


class SimilarSourceUnavailable(RuntimeError):
    pass


class ArtworkService:
    def __init__(self, ollama: OllamaClient, qdrant: QdrantService, visibility: ArtworkVisibilityClient):
        self.ollama = ollama
        self.qdrant = qdrant
        self.visibility = visibility

    async def analyze(
        self,
        artwork_id: int,
        title: str = "",
        description: str = "",
        existing_tags: list[str] | None = None,
        image_bytes: bytes | None = None,
    ) -> dict:
        start = time.monotonic()
        warnings = []
        vision_attempts: list[dict] = []
        analysis_source = "text"
        vision_model = ""
        try:
            async with asyncio.timeout(settings.artwork_analysis_timeout):
                if image_bytes:
                    try:
                        metadata = await self.ollama.analyze_image(
                            image_bytes=image_bytes, title=title, description=description,
                            existing_tags=existing_tags, attempts=vision_attempts,
                        )
                    except VisionOutputError as exc:
                        if not (title or description or existing_tags):
                            raise
                        warnings.append(f"Vision output rejected ({exc.reason}); analyzed text metadata instead")
                        metadata = await self.ollama.analyze_text(title=title, description=description, existing_tags=existing_tags)
                        analysis_source = "text_fallback"
                    else:
                        vision_model = settings.ollama_vision_model
                        analysis_source = "vision"
                else:
                    metadata = await self.ollama.analyze_text(title=title, description=description, existing_tags=existing_tags)
        except (TimeoutError, httpx.HTTPError, VisionOutputError):
            logger.warning("Artwork %d analysis failed; Vision attempts: %s", artwork_id, vision_attempts)
            raise

        if analysis_source == "text_fallback" or len(vision_attempts) > 1:
            logger.info("Artwork %d analysis source %s; Vision attempts: %s", artwork_id, analysis_source, vision_attempts)

        latency_ms = int((time.monotonic() - start) * 1000)

        meta_dict = metadata.model_dump() if hasattr(metadata, "model_dump") else dict(metadata)

        return {
            "artwork_id": artwork_id,
            **meta_dict,
            "chat_model": settings.ollama_chat_model,
            "vision_model": vision_model,
            "embedding_model": settings.ollama_embedding_model,
            "latency_ms": latency_ms,
            "analysis_source": analysis_source,
            "vision_attempts": vision_attempts,
            "warnings": warnings,
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
            "embedding_dimension": len(vector),
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
        diagnostics: bool = False,
    ) -> dict:
        start = time.monotonic()

        vector = await self.ollama.embed(query)
        embedded = time.monotonic() if diagnostics else 0
        dense_trace = {}
        results = await self.qdrant.search(
            vector=vector, limit=limit, filters=filters,
            **({"diagnostics": dense_trace} if diagnostics else {}),
        )
        retrieved = time.monotonic() if diagnostics else 0
        visible = await self.visibility.visible_ids(self._candidate_ids(results))
        checked = time.monotonic() if diagnostics else 0

        latency_ms = int((time.monotonic() - start) * 1000)

        return {
            "results": self._rank_visible(results, visible),
            "model": settings.ollama_embedding_model,
            "latency_ms": latency_ms,
            **({"diagnostics": {
                **dense_trace,
                "embedding_ms": round((embedded - start) * 1000, 3),
                "qdrant_ms": round((retrieved - embedded) * 1000, 3),
                "visibility_ms": round((checked - retrieved) * 1000, 3),
                "qdrant_count": len(results),
            }} if diagnostics else {}),
        }

    async def similar(
        self,
        artwork_id: int,
        limit: int = 20,
        filters: dict | None = None,
    ) -> dict:
        start = time.monotonic()

        if artwork_id not in await self.visibility.visible_ids([artwork_id]):
            raise SimilarSourceUnavailable()

        results = await self.qdrant.similar(
            artwork_id=artwork_id, limit=limit, filters=filters
        )

        visible = await self.visibility.visible_ids(self._candidate_ids(results) | {artwork_id})
        if artwork_id not in visible:
            raise SimilarSourceUnavailable()

        latency_ms = int((time.monotonic() - start) * 1000)

        return {
            "results": self._rank_visible(results, visible - {artwork_id}),
            "model": settings.ollama_embedding_model,
            "latency_ms": latency_ms,
        }

    @staticmethod
    def _candidate_ids(results: list[dict]) -> set[int]:
        return {item["artwork_id"] for item in results if is_artwork_id(item.get("artwork_id"))}

    @staticmethod
    def _rank_visible(results: list[dict], visible: set[int]) -> list[dict]:
        ranked = []
        for item in results:
            artwork_id = item.get("artwork_id")
            if is_artwork_id(artwork_id) and artwork_id in visible:
                ranked.append({**item, "rank": len(ranked) + 1})
        return ranked

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
