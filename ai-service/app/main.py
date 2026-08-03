import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI

from app.api.routes import router
from app.clients.ollama import OllamaClient
from app.clients.qdrant import QdrantService
from app.config import settings
from app.services.artwork_service import ArtworkService

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)

_service: ArtworkService | None = None


def get_service() -> ArtworkService:
    if _service is None:
        raise RuntimeError("ArtworkService not initialized — app not started yet")
    return _service


@asynccontextmanager
async def lifespan(app: FastAPI):
    global _service

    ollama = OllamaClient()
    qdrant = QdrantService()
    _service = ArtworkService(ollama=ollama, qdrant=qdrant)

    logger.info(
        "AI Service starting — Ollama: %s, Qdrant: %s, Collection: %s",
        settings.ollama_base_url,
        settings.qdrant_url,
        settings.qdrant_collection,
    )

    try:
        await _service.ensure_collection()
        logger.info("Qdrant collection ensured")
    except Exception:
        logger.warning("Could not ensure Qdrant collection at startup", exc_info=True)

    yield

    await ollama.close()
    await qdrant.close()
    _service = None
    logger.info("AI Service stopped")


app = FastAPI(
    title="LibraryStroll AI Service",
    version="0.1.0",
    lifespan=lifespan,
)

app.include_router(router)
