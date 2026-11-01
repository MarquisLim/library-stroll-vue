from unittest.mock import AsyncMock, patch

import pytest


def test_diagnostics_requires_api_key(test_app):
    with patch("app.api.dependencies.settings") as auth:
        auth.auth_enabled = True
        auth.ai_service_api_key = "test-key"
        assert test_app.get("/api/v1/diagnostics").status_code == 401


def test_diagnostics_reports_models_and_collection_without_generating(test_app, mock_ollama, mock_qdrant):
    from app.config import settings

    mock_ollama.list_models.return_value = [settings.ollama_chat_model, settings.ollama_vision_model, settings.ollama_embedding_model]
    mock_qdrant.diagnostics = AsyncMock(return_value={"collection": "artworks", "points_count": 12, "indexed_vectors_count": 10, "dimension": 1024, "distance": "Cosine", "collection_status": "green"})
    with patch("app.api.dependencies.settings") as auth:
        auth.auth_enabled = False
        response = test_app.get("/api/v1/diagnostics")
    assert response.status_code == 200
    assert response.json()["status"] == "ok"
    assert response.json()["qdrant"]["dimension"] == 1024
    assert all(model["installed"] for model in response.json()["ollama"]["models"])
    mock_ollama.embed.assert_not_called()
    mock_qdrant.ensure_collection.assert_not_called()


def test_diagnostics_preserves_independent_failure_results(test_app, mock_ollama, mock_qdrant):
    mock_ollama.list_models.side_effect = RuntimeError("sensitive connection detail")
    mock_qdrant.diagnostics = AsyncMock(return_value={"collection": "artworks", "points_count": 0, "dimension": 1024})
    with patch("app.api.dependencies.settings") as auth:
        auth.auth_enabled = False
        response = test_app.get("/api/v1/diagnostics")
    assert response.json()["status"] == "degraded"
    assert response.json()["ollama"]["status"] == "unavailable"
    assert response.json()["qdrant"]["status"] == "ok"
    assert "sensitive" not in response.text


def test_missing_models_are_explicitly_reported(test_app, mock_ollama, mock_qdrant):
    mock_ollama.list_models.return_value = []
    mock_qdrant.diagnostics = AsyncMock(side_effect=RuntimeError("offline"))
    with patch("app.api.dependencies.settings") as auth:
        auth.auth_enabled = False
        response = test_app.get("/api/v1/diagnostics")
    assert response.json()["ollama"]["status"] == "degraded"
    assert not any(model["installed"] for model in response.json()["ollama"]["models"])
    assert response.json()["qdrant"]["status"] == "unavailable"


def test_red_collection_is_degraded(test_app, mock_ollama, mock_qdrant):
    from app.config import settings

    mock_ollama.list_models.return_value = [settings.ollama_chat_model, settings.ollama_vision_model, settings.ollama_embedding_model]
    mock_qdrant.diagnostics = AsyncMock(return_value={"collection_status": "red"})
    with patch("app.api.dependencies.settings") as auth:
        auth.auth_enabled = False
        response = test_app.get("/api/v1/diagnostics")
    assert response.json()["status"] == "degraded"
    assert response.json()["qdrant"]["status"] == "degraded"


@pytest.mark.asyncio
async def test_collection_diagnostics_only_reads_existing_collection():
    from types import SimpleNamespace

    from qdrant_client.models import CollectionStatus, Distance, VectorParams

    from app.clients.qdrant import QdrantService

    client = AsyncMock()
    client.get_collection.return_value = SimpleNamespace(
        config=SimpleNamespace(params=SimpleNamespace(vectors=VectorParams(size=1024, distance=Distance.COSINE))),
        points_count=12, indexed_vectors_count=8, status=CollectionStatus.GREEN,
    )
    result = await QdrantService(collection="test-artworks", client=client).diagnostics()
    assert result == {"collection": "test-artworks", "points_count": 12, "indexed_vectors_count": 8, "dimension": 1024, "distance": "Cosine", "collection_status": "green"}
    client.get_collection.assert_awaited_once_with("test-artworks")
    client.create_collection.assert_not_called()
    client.upsert.assert_not_called()
