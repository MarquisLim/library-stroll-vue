from unittest.mock import patch, AsyncMock

from app.schemas.metadata import ArtworkMetadata
import httpx


def test_embedding_endpoint(test_app, mock_ollama):
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False

        mock_ollama.embed.return_value = [0.1, 0.2, 0.3]

        resp = test_app.post("/api/v1/embeddings", json={"text": "hello world"})
        assert resp.status_code == 200
        data = resp.json()
        assert data["dimensions"] == 3
        assert data["embedding"] == [0.1, 0.2, 0.3]


def test_analyze_endpoint(test_app, mock_ollama, artwork_service):
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False

        mock_metadata = ArtworkMetadata(
            summary="A cat painting",
            objects=["cat"],
            themes=["animals"],
            styles=["digital"],
            mood=["calm"],
            colors=["orange"],
            tags_ru=["кот"],
            tags_en=["cat"],
            confidence=0.9,
        )
        mock_ollama.analyze_text = AsyncMock(return_value=mock_metadata)

        resp = test_app.post(
            "/api/v1/artworks/analyze",
            data={"artwork_id": "1", "title": "Cat", "description": "A cat"},
        )
        assert resp.status_code == 200
        data = resp.json()
        assert data["artwork_id"] == 1
        assert data["summary"] == "A cat painting"


def test_index_endpoint(test_app, mock_qdrant):
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False

        mock_qdrant.upsert.return_value = "point-123"

        resp = test_app.post(
            "/api/v1/artworks/index",
            json={
                "artwork_id": 42,
                "metadata": {},
                "searchable_text": "cat painting",
                "payload": {"user_id": 1},
                "content_hash": "abc",
            },
        )
        assert resp.status_code == 200
        data = resp.json()
        assert data["artwork_id"] == 42
        assert data["qdrant_point_id"] == "point-123"
        assert data["status"] == "indexed"


def test_delete_index_endpoint(test_app, mock_qdrant):
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False

        resp = test_app.delete("/api/v1/artworks/42/index")
        assert resp.status_code == 200
        data = resp.json()
        assert data["artwork_id"] == 42
        assert data["status"] == "deleted"
        mock_qdrant.delete.assert_called_once_with(42)


def test_delete_index_idempotent(test_app, mock_qdrant):
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False

        test_app.delete("/api/v1/artworks/42/index")
        test_app.delete("/api/v1/artworks/42/index")
        assert mock_qdrant.delete.call_count == 2


def test_search_endpoint(test_app, mock_qdrant, mock_ollama):
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False

        mock_qdrant.search.return_value = [
            {"artwork_id": 1, "score": 0.95},
            {"artwork_id": 2, "score": 0.80},
        ]

        resp = test_app.post(
            "/api/v1/search",
            json={"query": "cat painting", "limit": 10},
        )
        assert resp.status_code == 200
        data = resp.json()
        assert len(data["results"]) == 2
        assert data["results"][0]["artwork_id"] == 1
        assert data["results"][0]["rank"] == 1
        assert data["results"][1]["rank"] == 2


def test_similar_endpoint(test_app, mock_qdrant, mock_ollama):
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False

        mock_qdrant.similar.return_value = [
            {"artwork_id": 5, "score": 0.88},
        ]

        resp = test_app.post(
            "/api/v1/similar",
            json={"artwork_id": 1, "limit": 5},
        )
        assert resp.status_code == 200
        data = resp.json()
        assert len(data["results"]) == 1
        assert data["results"][0]["artwork_id"] == 5


def test_rerank_disabled_returns_original_order(test_app):
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False

        resp = test_app.post(
            "/api/v1/rerank",
            json={"query": "test", "artwork_ids": [3, 1, 2]},
        )
        assert resp.status_code == 200
        data = resp.json()
        assert data["reranked"] is False
        ids = [r["artwork_id"] for r in data["results"]]
        assert ids == [3, 1, 2]


def test_search_with_empty_query_returns_422(test_app):
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False

        resp = test_app.post("/api/v1/search", json={"query": ""})
        assert resp.status_code == 422


def test_embed_timeout_returns_502(test_app, mock_ollama):
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False

        mock_ollama.embed.side_effect = TimeoutError("Ollama timeout")

        resp = test_app.post("/api/v1/embeddings", json={"text": "hello"})
        assert resp.status_code == 502


def test_vision_timeout_uses_explicit_text_fallback(test_app, mock_ollama):
    mock_ollama.analyze_image.side_effect = httpx.ReadTimeout("Vision slow")
    mock_ollama.analyze_text.return_value = ArtworkMetadata(summary="Museum description", tags_en=["cat"])
    with patch("app.api.dependencies.settings") as ms:
        ms.auth_enabled = False
        response = test_app.post("/api/v1/artworks/analyze", data={"artwork_id": 1, "title": "Museum cat"},
                                 files={"image": ("test.jpg", b"image", "image/jpeg")})
    assert response.status_code == 200
    body = response.json()
    assert body["analysis_source"] == "text_fallback"
    assert body["vision_model"] == ""
    assert body["warnings"]
    assert body["summary"] == "Museum description"
