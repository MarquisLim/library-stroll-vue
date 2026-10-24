import json

import httpx

from app.clients.ollama import OllamaClient


async def test_structured_client_disables_thinking_and_bounds_generation():
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
        assert requests[0]["options"]["num_predict"] > 0
        assert requests[0]["format"]["type"] == "object"
    finally:
        await client.close()
