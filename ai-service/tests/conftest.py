import pytest
from contextlib import asynccontextmanager
from unittest.mock import AsyncMock, patch

from fastapi import FastAPI
from fastapi.testclient import TestClient


@pytest.fixture
def mock_ollama():
    client = AsyncMock()
    client.health = AsyncMock(return_value=True)
    client.list_models = AsyncMock(return_value=["qwen3:8b", "qwen3-vl:4b"])
    client.embed = AsyncMock(return_value=[0.1] * 1024)
    client.get_embedding_dimension = AsyncMock(return_value=1024)
    client.analyze_image = AsyncMock()
    client.analyze_text = AsyncMock()
    client.close = AsyncMock()
    return client


@pytest.fixture
def mock_qdrant():
    client = AsyncMock()
    client.health = AsyncMock(return_value=True)
    client.ensure_collection = AsyncMock()
    client.upsert = AsyncMock(return_value="test-point-id")
    client.delete = AsyncMock()
    client.search = AsyncMock(return_value=[])
    client.similar = AsyncMock(return_value=[])
    client.close = AsyncMock()
    return client


@pytest.fixture
def artwork_service(mock_ollama, mock_qdrant):
    from app.services.artwork_service import ArtworkService
    return ArtworkService(ollama=mock_ollama, qdrant=mock_qdrant)


@pytest.fixture
def test_app(mock_ollama, mock_qdrant, artwork_service):
    """Build a test FastAPI app with mocked lifespan so no real connections are made."""
    from app.api.routes import router

    @asynccontextmanager
    async def noop_lifespan(app: FastAPI):
        yield

    test_fastapi = FastAPI(lifespan=noop_lifespan)
    test_fastapi.include_router(router)

    import app.main as main_module
    original_service = main_module._service
    main_module._service = artwork_service

    with TestClient(test_fastapi, raise_server_exceptions=False) as client:
        yield client

    main_module._service = original_service
