from unittest.mock import patch

from app.config import Settings


def test_auth_required_when_key_set(test_app):
    with patch("app.api.dependencies.settings") as mock_settings:
        mock_settings.auth_enabled = True
        mock_settings.ai_service_api_key = "secret-key"

        resp = test_app.post("/api/v1/embeddings", json={"text": "hello"})
        assert resp.status_code == 401

        resp = test_app.post(
            "/api/v1/embeddings",
            json={"text": "hello"},
            headers={"X-AI-Service-Key": "wrong-key"},
        )
        assert resp.status_code == 401

        resp = test_app.post(
            "/api/v1/embeddings",
            json={"text": "hello"},
            headers={"X-AI-Service-Key": "secret-key"},
        )
        assert resp.status_code == 200


def test_auth_not_required_when_key_empty(test_app):
    with patch("app.api.dependencies.settings") as mock_settings:
        mock_settings.auth_enabled = False
        mock_settings.ai_service_api_key = ""

        resp = test_app.post("/api/v1/embeddings", json={"text": "hello"})
        assert resp.status_code == 200
