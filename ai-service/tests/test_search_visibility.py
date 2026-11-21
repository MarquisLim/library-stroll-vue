import json
from unittest.mock import AsyncMock, Mock, call

import httpx
import pytest
from qdrant_client import AsyncQdrantClient

from app.clients.artwork_visibility import ArtworkVisibilityClient, VISIBILITY_PATH
from app.clients.qdrant import QdrantService
from app.config import settings


KEY = "visibility-test-key"
HEADERS = {"X-AI-Service-Key": KEY}
LARAVEL = "http://laravel.test"


@pytest.fixture
async def laravel(artwork_service, monkeypatch):
    monkeypatch.setattr(settings, "ai_service_api_key", KEY)
    monkeypatch.setattr(settings, "laravel_internal_url", LARAVEL)
    handler = Mock()

    def respond(request):
        assert request.method == "POST"
        assert str(request.url) == LARAVEL + VISIBILITY_PATH
        assert request.headers["X-AI-Service-Key"] == KEY
        assert request.headers["Accept"] == "application/json"
        return handler(json.loads(request.content)["artwork_ids"])

    async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
        artwork_service.visibility = ArtworkVisibilityClient(client)
        yield handler


@pytest.fixture
async def vectors(artwork_service, mock_ollama):
    qdrant = QdrantService(client=AsyncQdrantClient(location=":memory:"), collection="visibility")
    mock_ollama.embed.return_value = [1.0, 0.0]
    # All points still say "public", including works Laravel has hidden or deleted.
    for artwork_id in range(1, 8):
        await qdrant.upsert(
            artwork_id, [1.0, (artwork_id - 1) / 10],
            {"is_published": True, "is_private": False, "is_blocked": False},
        )
    artwork_service.qdrant = qdrant
    try:
        yield qdrant
    finally:
        await qdrant.close()


async def test_search_filters_stale_public_points_in_one_batch(test_app, vectors, laravel, mock_ollama, monkeypatch):
    before, _ = await vectors._client.scroll("visibility", limit=10, with_vectors=False)
    upsert = AsyncMock(wraps=vectors._client.upsert)
    delete = AsyncMock(wraps=vectors._client.delete)
    monkeypatch.setattr(vectors._client, "upsert", upsert)
    monkeypatch.setattr(vectors._client, "delete", delete)
    laravel.return_value = httpx.Response(200, json={"visible_ids": [7, 2]})

    response = test_app.post("/api/v1/search", json={"query": "landscape", "limit": 100}, headers=HEADERS)

    assert response.status_code == 200
    assert [(row["artwork_id"], row["rank"]) for row in response.json()["results"]] == [(2, 1), (7, 2)]
    laravel.assert_called_once_with(list(range(1, 8)))
    mock_ollama.embed.assert_awaited_once_with("landscape")
    after, _ = await vectors._client.scroll("visibility", limit=10, with_vectors=False)
    assert {row.id: row.payload for row in after} == {row.id: row.payload for row in before}
    upsert.assert_not_awaited()
    delete.assert_not_awaited()


def test_similar_checks_the_source_and_filters_candidates_without_any_inference(
    test_app, vectors, laravel, mock_ollama,
):
    laravel.side_effect = [
        httpx.Response(200, json={"visible_ids": [1]}),
        httpx.Response(200, json={"visible_ids": [1, 2]}),
    ]

    response = test_app.post("/api/v1/similar", json={"artwork_id": 1}, headers=HEADERS)

    assert response.status_code == 200
    assert [row["artwork_id"] for row in response.json()["results"]] == [2]
    assert laravel.call_args_list == [call([1]), call(list(range(1, 8)))]
    assert not mock_ollama.mock_calls


def test_similar_denies_the_source_before_using_its_vector(test_app, laravel, mock_qdrant, mock_ollama):
    laravel.return_value = httpx.Response(200, json={"visible_ids": []})

    response = test_app.post("/api/v1/similar", json={"artwork_id": 1}, headers=HEADERS)

    assert response.status_code == 404
    assert response.json() == {"detail": "artwork_not_available"}
    laravel.assert_called_once_with([1])
    mock_qdrant.similar.assert_not_awaited()
    assert not mock_ollama.mock_calls


def test_similar_denies_a_source_hidden_while_qdrant_was_running(test_app, laravel, mock_qdrant):
    mock_qdrant.similar.return_value = [{"artwork_id": 2, "score": 0.95}]
    laravel.side_effect = [
        httpx.Response(200, json={"visible_ids": [1]}),
        httpx.Response(200, json={"visible_ids": [2]}),
    ]

    response = test_app.post("/api/v1/similar", json={"artwork_id": 1}, headers=HEADERS)

    assert response.status_code == 404
    assert response.json() == {"detail": "artwork_not_available"}
    assert laravel.call_args_list == [call([1]), call([1, 2])]


@pytest.mark.parametrize("failure", [
    httpx.ConnectError("connection refused"),
    httpx.ReadTimeout("read timed out"),
    httpx.Response(503),
    httpx.Response(401),
    httpx.Response(307, headers={"location": "http://untrusted.test/"}),
    httpx.Response(200, text="not JSON"),
    httpx.Response(200, json={"visible_ids": [99]}),
    httpx.Response(200, json={"visible_ids": [True]}),
    httpx.Response(200, json={"visible_ids": ["1"]}),
])
def test_search_fails_closed_if_laravel_cannot_authorize_candidates(test_app, laravel, mock_qdrant, failure):
    mock_qdrant.search.return_value = [{"artwork_id": 1, "score": 1.0}]
    if isinstance(failure, Exception):
        laravel.side_effect = failure
    else:
        laravel.return_value = failure

    response = test_app.post("/api/v1/search", json={"query": "landscape"}, headers=HEADERS)

    assert response.status_code == 503
    assert response.json() == {"detail": "artwork_visibility_unavailable"}
    laravel.assert_called_once_with([1])


def test_similar_fails_closed_if_its_final_batch_cannot_be_checked(test_app, laravel, mock_qdrant):
    mock_qdrant.similar.return_value = [{"artwork_id": 2, "score": 0.95}]
    laravel.side_effect = [httpx.Response(200, json={"visible_ids": [1]}), httpx.Response(503)]

    response = test_app.post("/api/v1/similar", json={"artwork_id": 1}, headers=HEADERS)

    assert response.status_code == 503
    assert response.json() == {"detail": "artwork_visibility_unavailable"}


def test_similar_uses_one_batch_for_the_maximum_candidate_count(test_app, laravel, mock_qdrant):
    mock_qdrant.similar.return_value = [{"artwork_id": artwork_id, "score": 0.9} for artwork_id in range(2, 102)]
    laravel.side_effect = [
        httpx.Response(200, json={"visible_ids": [1]}),
        httpx.Response(200, json={"visible_ids": [1, 101]}),
    ]

    response = test_app.post("/api/v1/similar", json={"artwork_id": 1, "limit": 100}, headers=HEADERS)

    assert response.status_code == 200
    assert [row["artwork_id"] for row in response.json()["results"]] == [101]
    assert laravel.call_args_list == [call([1]), call(list(range(1, 102)))]


@pytest.mark.parametrize("path, body", [
    ("/api/v1/search", {"query": "landscape"}),
    ("/api/v1/similar", {"artwork_id": 1}),
])
def test_search_endpoints_require_a_configured_key(
    test_app, laravel, mock_ollama, mock_qdrant, monkeypatch, path, body,
):
    assert test_app.post(path, json=body).status_code == 401
    assert test_app.post(path, json=body, headers={"X-AI-Service-Key": "wrong"}).status_code == 401
    monkeypatch.setattr(settings, "ai_service_api_key", "   ")
    assert test_app.post(path, json=body, headers=HEADERS).status_code == 401
    laravel.assert_not_called()
    assert not mock_ollama.mock_calls
    assert not mock_qdrant.mock_calls


def test_search_fails_closed_without_a_laravel_address(test_app, laravel, mock_qdrant, monkeypatch):
    monkeypatch.setattr(settings, "laravel_internal_url", "")
    mock_qdrant.search.return_value = [{"artwork_id": 1, "score": 1.0}]

    response = test_app.post("/api/v1/search", json={"query": "landscape"}, headers=HEADERS)

    assert response.status_code == 503
    laravel.assert_not_called()
