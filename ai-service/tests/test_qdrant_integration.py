import uuid

import pytest
from qdrant_client import AsyncQdrantClient, models

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


async def test_inspect_returns_hidden_point_metadata_without_vector_or_other_payload(vectors):
    first = await vectors.upsert(7, [1.0, 0.0], public_payload(
        is_private=True, is_blocked=True, tags=["hidden"], content_hash="revision",
        schema_version=1, prompt_version=2, embedding_model="test-model", embedding_fingerprint="stored",
    ))

    result = (await vectors.inspect_artwork(7, first)).model_dump()

    assert result == {
        "artwork_id": 7, "collection": "audit", "dimension": 2, "saved_point": "matched",
        "has_duplicates": False, "vector_present": True,
        "points": [{
            "id": first, "artwork_id": 7, "content_hash": "revision", "schema_version": 1, "prompt_version": 2,
            "is_published": True, "is_private": True, "is_blocked": True, "embedding_model": "test-model",
            "embedding_model_digest": None, "embedding_fingerprint": "stored",
        }],
    }


async def test_inspect_distinguishes_absent_point_from_missing_collection(vectors):
    assert await vectors.inspect_artwork(7) is None
    assert not await vectors._client.collection_exists("audit")
    await vectors.ensure_collection(2)

    result = await vectors.inspect_artwork(7, str(uuid.uuid4()))

    assert result.points == []
    assert result.saved_point == "missing"
    assert result.vector_present is None


async def test_inspect_bounds_duplicate_results(vectors):
    for _ in range(3):
        await vectors.upsert(7, [1.0, 0.0], public_payload(), str(uuid.uuid4()))

    result = await vectors.inspect_artwork(7)

    assert result.has_duplicates
    assert len(result.points) == 2
    assert all(point.artwork_id == 7 for point in result.points)


async def test_inspect_detects_a_saved_id_belonging_to_another_artwork(vectors):
    own = await vectors.upsert(7, [1.0, 0.0], public_payload())
    other = await vectors.upsert(8, [1.0, 0.0], public_payload())

    result = await vectors.inspect_artwork(7, other)

    assert result.saved_point == "conflict"
    assert [point.id for point in result.points] == [own]
    assert (await vectors.inspect_artwork(9, other)).saved_point == "conflict"
    assert (await vectors.inspect_artwork(7, str(uuid.uuid4()))).saved_point == "missing"


async def test_inspect_distinguishes_a_payload_only_point(vectors):
    await vectors.ensure_collection(2)
    point = models.PointStruct(id=1, payload=public_payload(artwork_id=7), vector={})
    await vectors._client.upsert("audit", [point])

    result = await vectors.inspect_artwork(7)

    assert [point.id for point in result.points] == ["1"]
    assert result.vector_present is False
    assert result.points[0].embedding_model_digest is None
