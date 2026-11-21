from unittest.mock import call

import pytest

from app.config import settings


PATH = "/api/v1/artworks/7/index/inspect"
HEADERS = {"X-AI-Service-Key": "test-key"}


def test_inspection_requires_configured_key(test_app, mock_qdrant, monkeypatch):
    monkeypatch.setattr(settings, "ai_service_api_key", "")
    assert test_app.get(PATH, headers=HEADERS).status_code == 401
    monkeypatch.setattr(settings, "ai_service_api_key", "test-key")
    assert test_app.get(PATH).status_code == 401
    assert test_app.get(PATH, headers={"X-AI-Service-Key": "wrong"}).status_code == 401
    mock_qdrant.inspect_artwork.assert_not_called()


def test_inspection_only_reads_and_response_excludes_vector(test_app, mock_qdrant, mock_ollama, monkeypatch):
    monkeypatch.setattr(settings, "ai_service_api_key", "test-key")
    point_id = "596c0ea2-acfa-4df2-a82c-9b004008a104"
    mock_qdrant.inspect_artwork.return_value = {
        "artwork_id": 7, "dimension": 2, "collection": "audit", "saved_point": "matched",
        "has_duplicates": False, "vector_present": True,
        "points": [{"id": point_id, "artwork_id": 7, "vector": [1, 2]}],
    }

    response = test_app.get(PATH, headers=HEADERS, params={"point_id": point_id})

    assert response.status_code == 200
    assert "vector" not in response.json()["points"][0]
    assert response.json()["points"][0]["embedding_model_digest"] is None
    assert mock_qdrant.mock_calls == [call.inspect_artwork(7, point_id)]
    assert mock_ollama.mock_calls == []


@pytest.mark.parametrize("collection_missing", [True, False])
def test_inspection_distinguishes_qdrant_errors_from_an_absent_point(
    test_app, mock_qdrant, monkeypatch, collection_missing,
):
    monkeypatch.setattr(settings, "ai_service_api_key", "test-key")
    if collection_missing:
        mock_qdrant.inspect_artwork.return_value = None
    else:
        mock_qdrant.inspect_artwork.side_effect = RuntimeError("private server info")

    response = test_app.get(PATH, headers=HEADERS)

    assert response.status_code == 503
    assert response.json() == {"detail": "qdrant_collection_missing" if collection_missing else "qdrant_inspection_failed"}
