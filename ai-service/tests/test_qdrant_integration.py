import uuid

import pytest
from qdrant_client import AsyncQdrantClient

from app.clients.qdrant import QdrantService


@pytest.fixture
async def vectors():
    service = QdrantService(client=AsyncQdrantClient(location=":memory:"), collection="audit")
    yield service
    await service.close()


def public_payload(**changes):
    return {"is_published": True, "is_private": False, "is_blocked": False,
            "media_type": "image", **changes}


async def test_real_sdk_upsert_search_similar_and_delete(vectors):
    first = await vectors.upsert(1, [1.0, 0.0, 0.0], public_payload())
    await vectors.upsert(2, [0.9, 0.1, 0.0], public_payload())
    await vectors.upsert(3, [0.0, 0.0, 1.0], public_payload(is_private=True))
    results = await vectors.search([1.0, 0.0, 0.0])
    assert [r["artwork_id"] for r in results] == [1, 2]
    assert [r["artwork_id"] for r in await vectors.similar(1)] == [2]
    assert str(uuid.UUID(first)) == first
    await vectors.delete(1)
    await vectors.delete(1)
    assert await vectors.similar(1) == []
    assert [r["artwork_id"] for r in await vectors.search([1.0, 0.0, 0.0])] == [2]


async def test_first_write_retry_is_idempotent_and_preserves_authoritative_id(vectors):
    first = await vectors.upsert(7, [1.0, 0.0], public_payload(artwork_id=999))
    second = await vectors.upsert(7, [0.0, 1.0], public_payload())
    assert first == second
    assert (await vectors._client.count("audit")).count == 1
    assert (await vectors.search([0.0, 1.0]))[0]["artwork_id"] == 7


async def test_filters_work_without_exposing_private_draft_or_blocked_points(vectors):
    await vectors.upsert(1, [1.0, 0.0], public_payload(tags=["cat"], user_id=3))
    await vectors.upsert(2, [0.8, 0.2], public_payload(tags=["dog"], user_id=4))
    await vectors.upsert(3, [0.9, 0.1], public_payload(tags=["cat"], is_published=False))
    await vectors.upsert(4, [0.9, 0.1], public_payload(tags=["cat"], is_blocked=True))
    result = await vectors.search([1.0, 0.0], filters={"tags": "cat", "user_id": 3, "is_private": True})
    assert [r["artwork_id"] for r in result] == [1]


async def test_incompatible_dimension_is_explicit_and_missing_collection_is_not_healthy(vectors):
    assert await vectors.health() is False
    await vectors.delete(1)
    await vectors.ensure_collection(3)
    assert await vectors.health() is True
    with pytest.raises(ValueError, match="dimension"):
        await vectors.ensure_collection(4)
