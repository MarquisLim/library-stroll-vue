import pytest
from pydantic import ValidationError

from app.schemas.metadata import ArtworkMetadata
from app.schemas.requests import EmbeddingRequest, IndexRequest, SearchRequest


def test_metadata_deduplication():
    m = ArtworkMetadata(
        summary="  A painting  ",
        objects=["cat", "Cat", "#dog", "cat"],
        tags_ru=["кот", "Кот", "собака"],
        tags_en=["cat", "#Cat", "dog"],
        confidence=0.8,
    )
    d = m.deduplicated()
    assert d.summary == "A painting"
    assert d.objects == ["cat", "dog"]
    assert d.tags_ru == ["кот", "собака"]
    assert d.tags_en == ["cat", "dog"]


def test_metadata_tag_limit_via_dedup():
    m = ArtworkMetadata(
        tags_en=[f"tag{i}" for i in range(30)],
    )
    d = m.deduplicated()
    assert len(d.tags_en) == 15


def test_confidence_validation():
    with pytest.raises(ValidationError):
        ArtworkMetadata(confidence=1.5)

    with pytest.raises(ValidationError):
        ArtworkMetadata(confidence=-0.1)


def test_embedding_request_validation():
    with pytest.raises(ValidationError):
        EmbeddingRequest(text="")

    r = EmbeddingRequest(text="hello")
    assert r.text == "hello"


def test_search_request_defaults():
    r = SearchRequest(query="test")
    assert r.limit == 20
    assert r.filters == {}


def test_index_request():
    r = IndexRequest(
        artwork_id=42,
        searchable_text="a painting of a cat",
        payload={"user_id": 1},
        content_hash="abc123",
    )
    assert r.artwork_id == 42
    assert r.content_hash == "abc123"
