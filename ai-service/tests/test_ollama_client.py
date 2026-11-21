import base64
import json

import httpx
import pytest

from app.clients.ollama import METADATA_JSON_SCHEMA, OllamaClient
from app.config import settings


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
    ({"thinking": "private test text", "content": ""}, "length", "generation limit before returning valid metadata"),
    ({"content": '{"summary": "private test text'}, "length", "generation limit before returning valid metadata"),
    ({"content": '{"summary": ["private test text"]}'}, "stop", "invalid structured metadata"),
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
        with pytest.raises(ValueError, match=expected) as failure:
            await client.analyze_image(b"small image")
        assert requests == ["/api/chat"]
        assert "private test text" not in str(failure.value)
        assert "private test text" not in caplog.text
    finally:
        await client.close()
