import logging
import uuid

from qdrant_client import AsyncQdrantClient
from qdrant_client.models import (
    Distance,
    FieldCondition,
    Filter,
    MatchValue,
    PointStruct,
    VectorParams,
)

from app.config import settings

logger = logging.getLogger(__name__)


class QdrantService:
    def __init__(
        self,
        url: str | None = None,
        api_key: str | None = None,
        collection: str | None = None,
        timeout: int | None = None,
        client: AsyncQdrantClient | None = None,
    ):
        self.url = url or settings.qdrant_url
        self.collection = collection or settings.qdrant_collection
        _api_key = api_key if api_key is not None else settings.qdrant_api_key
        self._client = client or AsyncQdrantClient(
            url=self.url,
            api_key=_api_key or None,
            timeout=timeout or settings.qdrant_timeout,
        )

    async def health(self) -> bool:
        try:
            info = await self._client.get_collection(self.collection)
            return info is not None
        except Exception:
            return False

    async def ensure_collection(self, dimension: int) -> None:
        collections = await self._client.get_collections()
        existing_names = [c.name for c in collections.collections]

        if self.collection in existing_names:
            info = await self._client.get_collection(self.collection)
            existing_dim = info.config.params.vectors.size  # type: ignore[union-attr]
            if existing_dim != dimension:
                raise ValueError(
                    f"Collection '{self.collection}' exists with dimension "
                    f"{existing_dim}, but model produces {dimension}. "
                    f"Recreate the collection manually or use a different name."
                )
            logger.info(
                "Collection '%s' already exists with dimension %d",
                self.collection,
                dimension,
            )
            return

        await self._client.create_collection(
            collection_name=self.collection,
            vectors_config=VectorParams(size=dimension, distance=Distance.COSINE),
        )
        logger.info(
            "Created collection '%s' with dimension %d (cosine)",
            self.collection,
            dimension,
        )

    async def upsert(
        self,
        artwork_id: int,
        vector: list[float],
        payload: dict,
        point_id: str | None = None,
    ) -> str:
        await self.ensure_collection(len(vector))
        pid = point_id or str(uuid.uuid5(uuid.NAMESPACE_URL, f"librarystroll:artwork:{artwork_id}"))
        await self._client.upsert(
            collection_name=self.collection,
            points=[
                PointStruct(
                    id=pid,
                    vector=vector,
                    payload={**payload, "artwork_id": artwork_id},
                )
            ],
        )
        return pid

    async def delete(self, artwork_id: int) -> None:
        if not await self._client.collection_exists(self.collection):
            return
        await self._client.delete(
            collection_name=self.collection,
            points_selector=Filter(
                must=[
                    FieldCondition(
                        key="artwork_id",
                        match=MatchValue(value=artwork_id),
                    )
                ]
            ),
        )

    async def search(
        self,
        vector: list[float],
        limit: int = 20,
        filters: dict | None = None,
    ) -> list[dict]:
        qdrant_filter = self._build_filter(filters or {})

        results = await self._client.query_points(
            collection_name=self.collection,
            query=vector,
            limit=limit,
            query_filter=qdrant_filter,
        )

        return [
            {
                "artwork_id": point.payload.get("artwork_id"),  # type: ignore[union-attr]
                "score": point.score,
            }
            for point in results.points
            if point.payload  # type: ignore[union-attr]
        ]

    async def similar(
        self,
        artwork_id: int,
        limit: int = 20,
        filters: dict | None = None,
    ) -> list[dict]:
        must_conditions = [
            FieldCondition(key="artwork_id", match=MatchValue(value=artwork_id))
        ]
        existing = await self._client.scroll(
            collection_name=self.collection,
            scroll_filter=Filter(must=must_conditions),
            limit=1,
            with_vectors=True,
        )

        points, _ = existing
        if not points:
            return []

        source_vector = points[0].vector

        exclude_conditions: list[FieldCondition] = [
            FieldCondition(key="artwork_id", match=MatchValue(value=artwork_id))
        ]
        base_filter = self._build_filter(filters or {})
        if base_filter.must is None:
            base_filter.must = []
        base_filter.must_not = exclude_conditions  # type: ignore[assignment]

        results = await self._client.query_points(
            collection_name=self.collection,
            query=source_vector,  # type: ignore[arg-type]
            limit=limit,
            query_filter=base_filter,
        )

        return [
            {
                "artwork_id": point.payload.get("artwork_id"),  # type: ignore[union-attr]
                "score": point.score,
            }
            for point in results.points
            if point.payload  # type: ignore[union-attr]
        ]

    def _build_filter(self, filters: dict) -> Filter:
        conditions: list[FieldCondition] = []

        allowed = {'media_type', 'user_id', 'has_ai', 'tags'}
        safe_filters = {key: value for key, value in filters.items() if key in allowed}
        safe_filters.update(is_published=True, is_private=False, is_blocked=False)
        for key, value in safe_filters.items():
            if value is not None:
                conditions.append(
                    FieldCondition(key=key, match=MatchValue(value=value))
                )

        return Filter(must=conditions)

    async def close(self) -> None:
        await self._client.close()
