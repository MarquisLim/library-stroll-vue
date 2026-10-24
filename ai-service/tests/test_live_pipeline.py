"""Opt-in real Ollama + real in-memory Qdrant API check. No production data writes."""
import os
from pathlib import Path

import httpx
import pytest
from fastapi import FastAPI
from qdrant_client import AsyncQdrantClient

from app.api.routes import router
from app.clients.ollama import OllamaClient
from app.clients.qdrant import QdrantService
from app.config import settings
from app.services.artwork_service import ArtworkService


@pytest.mark.skipif(os.environ.get("RUN_AI_SMOKE") != "1", reason="Set RUN_AI_SMOKE=1 for real inference")
async def test_real_analysis_index_search_similar_retry_and_delete(monkeypatch):
    import app.main as main

    ollama = OllamaClient()
    qdrant = QdrantService(client=AsyncQdrantClient(location=":memory:"), collection="smoke")
    monkeypatch.setattr(main, "_service", ArtworkService(ollama, qdrant))
    app = FastAPI()
    app.include_router(router)
    headers = {"X-AI-Service-Key": settings.ai_service_api_key} if settings.auth_enabled else {}
    try:
        async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://smoke", headers=headers) as client:
            # Exercise the vision request format used by the Laravel job with a real image.
            image = Path(__file__).resolve().parents[2] / "public/images/main/gallery/1.jpg"
            response = await client.post("/api/v1/artworks/analyze", data={"artwork_id": "1", "title": "Sample artwork"},
                                         files={"image": ("sample.jpg", image.read_bytes(), "image/jpeg")})
            assert response.status_code == 200, response.text
            metadata = response.json()
            assert metadata["summary"]
            assert metadata["analysis_source"] in {"vision", "text_fallback"}
            for aid, text in [(1, "A ginger cat sitting beside a window. Кот у окна."),
                              (2, "Technical blueprint of industrial machinery, factory engines.")]:
                body = {"artwork_id": aid, "metadata": metadata if aid == 1 else {}, "searchable_text": text,
                        "payload": {"is_published": True, "is_private": False, "is_blocked": False}}
                response = await client.post("/api/v1/artworks/index", json=body)
                assert response.status_code == 200, response.text
                assert response.json()["embedding_dimension"] == 1024
                if aid == 1:
                    first_id = response.json()["qdrant_point_id"]
                    retry = await client.post("/api/v1/artworks/index", json=body)
                    assert retry.json()["qdrant_point_id"] == first_id
            assert (await qdrant._client.count("smoke")).count == 2
            response = await client.post("/api/v1/search", json={"query": "рыжий кот у окна"})
            assert response.status_code == 200, response.text
            assert response.json()["results"][0]["artwork_id"] == 1
            response = await client.post("/api/v1/similar", json={"artwork_id": 1})
            assert response.status_code == 200, response.text
            assert [r["artwork_id"] for r in response.json()["results"]] == [2]
            for aid in (1, 2):
                response = await client.delete(f"/api/v1/artworks/{aid}/index")
                assert response.status_code == 200, response.text
            assert (await qdrant._client.count("smoke")).count == 0
            print(f"REAL_PIPELINE_OK: analysis={metadata['analysis_source']}, 1024d embeddings, idempotency, RU search, similar, delete")
    finally:
        await ollama.close()
        await qdrant.close()
