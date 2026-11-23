import asyncio
import json

import httpx
import pytest

from app.clients.ollama import OllamaClient
from app.config import settings


PATH = "/api/v1/assistant/normalize"
HEADERS = {"X-AI-Service-Key": "assistant-test-key"}
QUERY = "Найди изображения птиц у воды"


@pytest.fixture
def assistant(test_app, monkeypatch):
    monkeypatch.setattr(settings, "ai_service_api_key", HEADERS["X-AI-Service-Key"])
    monkeypatch.setattr(settings, "ai_assistant_enabled", True)
    monkeypatch.setattr(settings, "ai_assistant_llm_enabled", True)
    return test_app


@pytest.fixture
def chat(artwork_service, respx_mock):
    ollama = OllamaClient(base_url="http://ollama.test")
    artwork_service.ollama = ollama
    yield respx_mock.post("http://ollama.test/api/chat")
    asyncio.run(ollama.close())


def ollama_response(result, **envelope):
    return httpx.Response(200, json={
        "done": True,
        "done_reason": "stop",
        "message": {"content": json.dumps(result, ensure_ascii=False)},
        **envelope,
    })


@pytest.mark.parametrize(("query", "normalized"), [
    (QUERY, "птиц у воды"),
    ("Show me images of birds by water", "birds by water"),
])
def test_normalizes_ru_and_en_with_one_bounded_chat_call(assistant, chat, mock_qdrant, mock_visibility, query, normalized):
    result = {"action": "search", "search_query": normalized, "clarification_code": None}
    chat.mock(return_value=ollama_response(result))

    response = assistant.post(PATH, json={"query": query}, headers=HEADERS)

    assert response.status_code == 200
    assert response.json() == result
    assert chat.call_count == 1
    payload = json.loads(chat.calls[0].request.content)
    assert payload["model"] == settings.ollama_chat_model
    assert payload["think"] is False and payload["stream"] is False
    assert payload["options"] == {"temperature": 0.0, "num_predict": 256}
    assert payload["messages"][0]["role"] == "system"
    assert payload["messages"][1:] == [{"role": "user", "content": query}]
    assert "tools" not in payload
    assert payload["format"]["additionalProperties"] is False
    assert set(payload["format"]["required"]) == {"action", "search_query", "clarification_code"}
    assert not mock_qdrant.mock_calls
    assert not mock_visibility.mock_calls


@pytest.mark.parametrize("code", ["ambiguous_subject", "too_broad"])
def test_allows_fixed_clarification_codes(assistant, chat, code):
    result = {"action": "clarify", "search_query": None, "clarification_code": code}
    chat.mock(return_value=ollama_response(result))

    response = assistant.post(PATH, json={"query": "покажи что-нибудь"}, headers=HEADERS)

    assert response.status_code == 200
    assert response.json() == result


@pytest.mark.parametrize("headers", [{}, {"X-AI-Service-Key": "wrong"}])
def test_requires_the_service_key(assistant, mock_ollama, mock_qdrant, headers):
    assert assistant.post(PATH, json={"query": QUERY}, headers=headers).status_code == 401
    assert not mock_ollama.mock_calls
    assert not mock_qdrant.mock_calls


@pytest.mark.parametrize("server_key", ["", " "])
def test_missing_server_key_fails_closed(assistant, mock_ollama, monkeypatch, server_key):
    monkeypatch.setattr(settings, "ai_service_api_key", server_key)

    assert assistant.post(PATH, json={"query": QUERY}, headers=HEADERS).status_code == 401
    assert not mock_ollama.mock_calls


@pytest.mark.parametrize("flag", ["ai_assistant_enabled", "ai_assistant_llm_enabled"])
def test_endpoint_requires_both_flags(assistant, mock_ollama, mock_qdrant, monkeypatch, flag):
    monkeypatch.setattr(settings, flag, False)

    assert assistant.post(PATH, json={"query": QUERY}, headers=HEADERS).status_code == 404
    assert not mock_ollama.mock_calls
    assert not mock_qdrant.mock_calls


@pytest.mark.parametrize("body", [
    {"query": " "},
    {"query": "я" * 501},
    {"query": " " + "я" * 500},
    {"query": 42},
    {"query": QUERY, "user_id": 1},
    {"query": QUERY, "history": []},
])
def test_rejects_invalid_input_before_ollama(assistant, mock_ollama, body):
    assert assistant.post(PATH, json=body, headers=HEADERS).status_code == 422
    assert not mock_ollama.mock_calls


@pytest.mark.parametrize("upstream", [
    httpx.Response(200, text="invalid envelope"),
    httpx.Response(200, json=[]),
    httpx.Response(200, json={"message": {"content": ""}}),
    httpx.Response(200, json={"message": {"content": "private malformed model response"}}),
    ollama_response({"action": "search", "search_query": "birds", "clarification_code": None}, done_reason="length"),
    ollama_response({"action": "search", "search_query": "birds", "clarification_code": None}, done=False),
    ollama_response({"action": "search", "search_query": " ", "clarification_code": None}),
    ollama_response({"action": "search", "search_query": "я" * 501, "clarification_code": None}),
    ollama_response({"action": "clarify", "search_query": None, "clarification_code": "unknown"}),
    ollama_response({"action": "clarify", "search_query": "birds", "clarification_code": "too_broad"}),
    ollama_response({"action": "search", "search_query": "birds", "clarification_code": None, "url": "https://invalid.test"}),
    ollama_response({"action": "search", "search_query": "birds"}),
    httpx.Response(503, text="private Ollama internal details"),
    httpx.Response(302, headers={"Location": "http://redirect.test/"}),
])
def test_invalid_or_failed_ollama_response_is_sanitized(assistant, chat, mock_qdrant, caplog, upstream):
    chat.mock(return_value=upstream)

    response = assistant.post(PATH, json={"query": QUERY}, headers=HEADERS)

    assert response.status_code == 502
    assert response.json() == {"detail": "assistant_normalization_unavailable"}
    assert chat.call_count == 1
    assert QUERY not in caplog.text
    assert "private " not in caplog.text
    assert "Interpret one Russian or English" not in caplog.text
    assert not mock_qdrant.mock_calls


def test_transport_timeout_is_sanitized_and_not_retried(assistant, chat, caplog):
    chat.mock(side_effect=httpx.ReadTimeout("private timeout details"))

    response = assistant.post(PATH, json={"query": QUERY}, headers=HEADERS)

    assert response.status_code == 504
    assert response.json() == {"detail": "assistant_normalization_timeout"}
    assert chat.call_count == 1
    assert "private timeout details" not in caplog.text
    assert QUERY not in caplog.text


def test_generation_has_a_wall_clock_deadline(assistant, chat, monkeypatch):
    attempts = []

    async def slow(request):
        attempts.append(request)
        await asyncio.sleep(1)
        return ollama_response({"action": "search", "search_query": QUERY, "clarification_code": None})

    monkeypatch.setattr(settings, "ollama_assistant_timeout", 0.05)
    chat.mock(side_effect=slow)

    response = assistant.post(PATH, json={"query": QUERY}, headers=HEADERS)

    assert response.status_code == 504
    assert len(attempts) == 1
