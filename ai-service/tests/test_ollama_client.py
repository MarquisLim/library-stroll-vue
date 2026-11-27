import asyncio
import base64
import json

import httpx
import pytest

from app.clients.ollama import METADATA_JSON_SCHEMA, VISION_RETRY_JSON_SCHEMA, OllamaClient, VisionOutputError
from app.config import settings
from app.services.artwork_service import ArtworkService


VALID_CONTENT = json.dumps({
    "summary": "A blue circle",
    "objects": ["circle"], "themes": [], "styles": [], "mood": [], "colors": ["blue"],
    "tags_ru": ["круг"], "tags_en": ["circle"], "confidence": 0.85,
})


def vision_response(content=VALID_CONTENT, reason="stop"):
    return httpx.Response(200, json={
        "done": True, "done_reason": reason, "eval_count": 42, "eval_duration": 9,
        "message": {"content": content},
    })


async def test_structured_text_client_keeps_its_own_generation_budget(monkeypatch):
    monkeypatch.setattr(settings, "ollama_think", False)
    monkeypatch.setattr(settings, "ollama_num_predict", 768)
    requests = []

    async def handler(request):
        requests.append(json.loads(request.content))
        return httpx.Response(200, json={"message": {"content": json.dumps({
            "summary": "A cat", "tags_en": ["cat", "Cat"], "confidence": 0.9,
        })}})

    client = OllamaClient()
    await client._client.aclose()
    client._client = httpx.AsyncClient(transport=httpx.MockTransport(handler), base_url="http://ollama")
    try:
        result = await client.analyze_text(title="Cat")
        assert result.tags_en == ["cat"]
        assert requests[0]["think"] is False
        assert requests[0]["options"]["num_predict"] == 768
        assert requests[0]["format"]["type"] == "object"
    finally:
        await client.close()


async def test_vision_has_its_own_budget_and_uses_the_final_structured_answer(monkeypatch):
    monkeypatch.setattr(settings, "ollama_think", False)
    monkeypatch.setattr(settings, "ollama_vision_num_predict", 3072)

    async def handler(request):
        payload = json.loads(request.content)
        assert payload["model"] == settings.ollama_vision_model
        assert payload["think"] is False
        assert payload["options"]["num_predict"] == 3072
        assert payload["format"] == METADATA_JSON_SCHEMA
        assert base64.b64decode(payload["messages"][1]["images"][0]) == b"small image"
        return httpx.Response(200, json={
            "done": True,
            "done_reason": "stop",
            "message": {"thinking": "unstructured reasoning", "content": json.dumps({
                "summary": "A blue circle",
                "objects": ["circle"],
                "themes": ["geometry"],
                "styles": ["minimalism"],
                "mood": ["calm"],
                "colors": ["blue"],
                "tags_ru": ["круг"],
                "tags_en": ["circle", "Circle"],
                "confidence": 0.85,
            })},
        })

    client = OllamaClient()
    await client._client.aclose()
    client._client = httpx.AsyncClient(transport=httpx.MockTransport(handler), base_url="http://ollama")
    try:
        result = await client.analyze_image(b"small image")
        assert result.summary == "A blue circle"
        assert result.tags_en == ["circle"]
    finally:
        await client.close()


@pytest.mark.parametrize("message, done_reason, expected", [
    ({"thinking": "private test text", "content": ""}, "length", "generation_limit"),
    ({"content": '{"summary": "private test text'}, "length", "generation_limit"),
    ({"content": '{"summary": ["private test text"]}'}, "stop", "missing_required_fields"),
])
async def test_vision_failure_is_bounded_and_sanitized(message, done_reason, expected, caplog):
    requests = []

    async def handler(request):
        requests.append(request.url.path)
        return httpx.Response(200, json={"done": True, "done_reason": done_reason, "message": message})

    client = OllamaClient()
    await client._client.aclose()
    client._client = httpx.AsyncClient(transport=httpx.MockTransport(handler), base_url="http://ollama")
    try:
        with pytest.raises(VisionOutputError, match=expected) as failure:
            await client.analyze_image(b"small image")
        assert requests == ["/api/chat", "/api/chat"]
        assert "private test text" not in str(failure.value)
        assert "private test text" not in caplog.text
    finally:
        await client.close()


@pytest.mark.parametrize("content, reason, outcome", [
    (VALID_CONTENT, "length", "generation_limit"),
    ('{"summary":', "stop", "invalid_structured_metadata"),
    ('{"summary":"partial"}', "stop", "missing_required_fields"),
])
async def test_retry_returns_vision_with_bounded_options_and_telemetry(content, reason, outcome):
    requests = []

    async def handler(request):
        requests.append(json.loads(request.content))
        if len(requests) == 1:
            return vision_response(content, reason)
        return vision_response()

    client = OllamaClient()
    await client._client.aclose()
    client._client = httpx.AsyncClient(transport=httpx.MockTransport(handler), base_url="http://ollama")
    try:
        result = await ArtworkService(client, None, None).analyze(88, "Artwork", image_bytes=b"image")
    finally:
        await client.close()
    assert result["analysis_source"] == "vision"
    assert result["warnings"] == []
    attempts = result["vision_attempts"]
    assert [attempt["outcome"] for attempt in attempts] == [outcome, "vision"]
    assert attempts[1]["retry_reason"] == outcome
    assert attempts[1]["done_reason"] == "stop" and attempts[1]["eval_count"] == 42
    assert requests[0]["options"] == {"temperature": 0.1, "num_predict": 3072}
    assert requests[1]["options"] == {"temperature": 0, "num_predict": 4096, "num_ctx": 8192}
    assert requests[1]["format"] == VISION_RETRY_JSON_SCHEMA
    assert all(request["think"] is False for request in requests)


async def test_unavailable_ollama_does_not_retry_or_fallback():
    calls = []

    async def handler(request):
        calls.append(request)
        raise httpx.ConnectError("offline", request=request)

    client = OllamaClient()
    await client._client.aclose()
    client._client = httpx.AsyncClient(transport=httpx.MockTransport(handler), base_url="http://ollama")
    try:
        with pytest.raises(httpx.ConnectError):
            await ArtworkService(client, None, None).analyze(1, "Artwork", image_bytes=b"image")
    finally:
        await client.close()
    assert len(calls) == 1


@pytest.mark.parametrize("fallback_stalls", [False, True])
async def test_wall_clock_analysis_budget_includes_text_fallback(monkeypatch, fallback_stalls):
    monkeypatch.setattr(settings, "ollama_vision_timeout", 0.1)
    monkeypatch.setattr(settings, "ollama_vision_retry_timeout", 0.1)
    monkeypatch.setattr(settings, "artwork_analysis_timeout", 0.2)
    calls = []

    async def handler(request):
        calls.append(json.loads(request.content)["model"])
        if len(calls) <= 2:
            return vision_response("", "length")
        if fallback_stalls:
            await asyncio.sleep(2)
        return vision_response()

    client = OllamaClient()
    await client._client.aclose()
    client._client = httpx.AsyncClient(transport=httpx.MockTransport(handler), base_url="http://ollama")
    try:
        if fallback_stalls:
            async with asyncio.timeout(1):
                with pytest.raises(TimeoutError):
                    await ArtworkService(client, None, None).analyze(1, "Artwork", image_bytes=b"image")
        else:
            result = await ArtworkService(client, None, None).analyze(1, "Artwork", image_bytes=b"image")
            assert result["analysis_source"] == "text_fallback"
            assert len(result["vision_attempts"]) == 2
    finally:
        await client.close()
    assert calls == [settings.ollama_vision_model, settings.ollama_vision_model, settings.ollama_chat_model]
