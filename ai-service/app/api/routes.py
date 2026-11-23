import asyncio
import logging
from typing import Annotated
from uuid import UUID

import httpx
from fastapi import APIRouter, Depends, File, Form, HTTPException, Path, UploadFile
from fastapi.responses import JSONResponse

from app.api.dependencies import require_api_key, verify_api_key
from app.clients.artwork_visibility import VisibilityUnavailable
from app.clients.ollama import AssistantComposeBudgetError
from app.config import settings
from app.schemas.assistant import AssistantComposePlan, AssistantComposeRequest
from app.schemas.requests import (
    AssistantNormalizeRequest,
    EmbeddingRequest,
    IndexRequest,
    RerankRequest,
    SearchRequest,
    SimilarRequest,
)
from app.schemas.responses import (
    AnalyzeResponse,
    ArtworkIndexInspection,
    AssistantNormalizeResponse,
    DeleteIndexResponse,
    EmbeddingResponse,
    HealthResponse,
    HealthServiceStatus,
    IndexResponse,
    RerankResponse,
    SearchResponse,
    SearchResultItem,
    SimilarResponse,
)
from app.services.artwork_service import ArtworkService, SimilarSourceUnavailable

logger = logging.getLogger(__name__)

router = APIRouter()


def get_artwork_service() -> ArtworkService:
    from app.main import get_service

    return get_service()


@router.post(
    "/api/v1/assistant/normalize",
    response_model=AssistantNormalizeResponse,
    dependencies=[Depends(require_api_key)],
)
async def normalize_assistant_query(
    body: AssistantNormalizeRequest,
    svc: Annotated[ArtworkService, Depends(get_artwork_service)],
) -> AssistantNormalizeResponse:
    if not (settings.ai_assistant_enabled and settings.ai_assistant_llm_enabled):
        raise HTTPException(status_code=404, detail="assistant_normalization_disabled")
    try:
        return await svc.ollama.normalize_assistant_query(body.query)
    except (TimeoutError, httpx.TimeoutException):
        logger.warning("Assistant normalization timed out")
        raise HTTPException(status_code=504, detail="assistant_normalization_timeout") from None
    except (httpx.HTTPError, ValueError) as exc:
        logger.warning("Assistant normalization failed (%s)", type(exc).__name__)
        raise HTTPException(status_code=502, detail="assistant_normalization_unavailable") from None


@router.post(
    "/api/v1/assistant/compose",
    response_model=AssistantComposePlan,
    dependencies=[Depends(require_api_key)],
)
async def compose_assistant_answer(
    body: AssistantComposeRequest,
    svc: Annotated[ArtworkService, Depends(get_artwork_service)],
) -> AssistantComposePlan:
    if not (settings.ai_assistant_enabled and settings.ai_assistant_rag_enabled):
        raise HTTPException(status_code=404, detail="assistant_composition_disabled")
    try:
        plan = await svc.ollama.compose_assistant_answer(body)
        allowed = {fact.pair() for fact in body.facts}
        if any(selection.pair() not in allowed for selection in plan.selections):
            raise ValueError("Unknown assistant fact")
        return plan
    except AssistantComposeBudgetError:
        raise HTTPException(status_code=422, detail="assistant_context_too_large") from None
    except (TimeoutError, httpx.TimeoutException):
        logger.warning("Assistant composition timed out")
        raise HTTPException(status_code=504, detail="assistant_composition_timeout") from None
    except (httpx.HTTPError, ValueError) as exc:
        logger.warning("Assistant composition failed (%s)", type(exc).__name__)
        raise HTTPException(status_code=502, detail="assistant_composition_unavailable") from None


@router.get("/api/v1/diagnostics", dependencies=[Depends(verify_api_key)])
async def diagnostics(svc: Annotated[ArtworkService, Depends(get_artwork_service)]):
    """Bounded, read-only diagnostics; installed models are not inference tests."""
    async def models_check():
        try:
            async with asyncio.timeout(5):
                installed = set(await svc.ollama.list_models())
            models = [
                {"role": role, "name": name, "installed": name in installed}
                for role, name in [
                    ("chat", settings.ollama_chat_model),
                    ("vision", settings.ollama_vision_model),
                    ("embedding", settings.ollama_embedding_model),
                ]
            ]
            return {"status": "ok" if all(item["installed"] for item in models) else "degraded", "models": models}
        except Exception:
            logger.warning("Ollama diagnostics unavailable")
            return {"status": "unavailable", "models": []}

    async def vectors_check():
        try:
            async with asyncio.timeout(5):
                details = await svc.qdrant.diagnostics()
            return {**details, "status": "degraded" if details.get("collection_status") == "red" else "ok"}
        except Exception:
            logger.warning("Qdrant diagnostics unavailable")
            return {"status": "unavailable", "collection": settings.qdrant_collection}

    ollama, qdrant = await asyncio.gather(models_check(), vectors_check())
    return {
        "status": "ok" if ollama["status"] == qdrant["status"] == "ok" else "degraded",
        "ollama": ollama,
        "qdrant": qdrant,
    }


@router.get("/health", response_model=HealthResponse)
async def health():
    from app.main import get_service

    svc = get_service()
    ollama_ok = await svc.ollama.health()
    qdrant_ok = await svc.qdrant.health()

    status = "ok" if (ollama_ok and qdrant_ok) else "degraded"

    return HealthResponse(
        status=status,
        services=HealthServiceStatus(
            ollama="ok" if ollama_ok else "unavailable",
            qdrant="ok" if qdrant_ok else "unavailable",
        ),
    )


@router.get("/ready", response_model=HealthResponse)
async def readiness():
    """Recover initial collection setup when dependencies start after the API."""
    result = await health()
    if result.services.ollama == "ok" and result.services.qdrant != "ok":
        try:
            await get_artwork_service().ensure_collection()
            result = await health()
        except Exception:
            logger.warning("Dependencies are not ready for collection initialization", exc_info=True)

    if result.status != "ok":
        return JSONResponse(status_code=503, content=result.model_dump())
    return result


@router.post(
    "/api/v1/embeddings",
    response_model=EmbeddingResponse,
    dependencies=[Depends(verify_api_key)],
)
async def create_embedding(
    body: EmbeddingRequest,
    svc: Annotated[ArtworkService, Depends(get_artwork_service)],
):
    try:
        result = await svc.embed(body.text)
        return EmbeddingResponse(**result)
    except Exception as exc:
        logger.exception("Embedding failed")
        raise HTTPException(status_code=502, detail=str(exc) or type(exc).__name__)


@router.post(
    "/api/v1/artworks/analyze",
    response_model=AnalyzeResponse,
    dependencies=[Depends(verify_api_key)],
)
async def analyze_artwork(
    artwork_id: int = Form(...),
    title: str = Form(""),
    description: str = Form(""),
    existing_tags: str = Form(""),
    image: UploadFile | None = File(None),
    svc: ArtworkService = Depends(get_artwork_service),
):
    tags = [t.strip() for t in existing_tags.split(",") if t.strip()] if existing_tags else []
    image_bytes = await image.read() if image else None

    try:
        result = await svc.analyze(
            artwork_id=artwork_id,
            title=title,
            description=description,
            existing_tags=tags,
            image_bytes=image_bytes,
        )
        return AnalyzeResponse(**result)
    except Exception as exc:
        logger.exception("Analysis failed for artwork %d", artwork_id)
        raise HTTPException(status_code=502, detail=str(exc) or type(exc).__name__)


@router.post(
    "/api/v1/artworks/index",
    response_model=IndexResponse,
    dependencies=[Depends(verify_api_key)],
)
async def index_artwork(
    body: IndexRequest,
    svc: Annotated[ArtworkService, Depends(get_artwork_service)],
):
    try:
        result = await svc.index(
            artwork_id=body.artwork_id,
            metadata=body.metadata,
            searchable_text=body.searchable_text,
            payload=body.payload,
            content_hash=body.content_hash,
            idempotency_key=body.idempotency_key,
        )
        return IndexResponse(**result)
    except Exception as exc:
        logger.exception("Indexing failed for artwork %d", body.artwork_id)
        raise HTTPException(status_code=502, detail=str(exc) or type(exc).__name__)


@router.get(
    "/api/v1/artworks/{artwork_id}/index/inspect",
    response_model=ArtworkIndexInspection,
    dependencies=[Depends(require_api_key)],
)
async def inspect_artwork_index(
    artwork_id: Annotated[int, Path(gt=0, le=2**63 - 1)],
    svc: Annotated[ArtworkService, Depends(get_artwork_service)],
    point_id: UUID | None = None,
):
    try:
        async with asyncio.timeout(5):
            result = await svc.qdrant.inspect_artwork(artwork_id, str(point_id) if point_id else None)
    except Exception:
        logger.warning("Qdrant index inspection failed")
        raise HTTPException(status_code=503, detail="qdrant_inspection_failed") from None

    if result is None:
        raise HTTPException(status_code=503, detail="qdrant_collection_missing")
    return result


@router.delete(
    "/api/v1/artworks/{artwork_id}/index",
    response_model=DeleteIndexResponse,
    dependencies=[Depends(verify_api_key)],
)
async def delete_artwork_index(
    artwork_id: int,
    svc: Annotated[ArtworkService, Depends(get_artwork_service)],
):
    try:
        result = await svc.delete_index(artwork_id)
        return DeleteIndexResponse(**result)
    except Exception as exc:
        logger.exception("Delete index failed for artwork %d", artwork_id)
        raise HTTPException(status_code=502, detail=str(exc) or type(exc).__name__)


@router.post(
    "/api/v1/search",
    response_model=SearchResponse,
    dependencies=[Depends(require_api_key)],
)
async def search(
    body: SearchRequest,
    svc: Annotated[ArtworkService, Depends(get_artwork_service)],
):
    try:
        result = await svc.search(
            query=body.query, limit=body.limit, filters=body.filters
        )
        return SearchResponse(
            results=[SearchResultItem(**r) for r in result["results"]],
            model=result["model"],
            latency_ms=result["latency_ms"],
        )
    except VisibilityUnavailable:
        raise HTTPException(status_code=503, detail="artwork_visibility_unavailable") from None
    except Exception as exc:
        logger.exception("Search failed")
        raise HTTPException(status_code=502, detail=str(exc) or type(exc).__name__)


@router.post(
    "/api/v1/similar",
    response_model=SimilarResponse,
    dependencies=[Depends(require_api_key)],
)
async def similar(
    body: SimilarRequest,
    svc: Annotated[ArtworkService, Depends(get_artwork_service)],
):
    try:
        result = await svc.similar(
            artwork_id=body.artwork_id, limit=body.limit, filters=body.filters
        )
        return SimilarResponse(
            results=[SearchResultItem(**r) for r in result["results"]],
            model=result["model"],
            latency_ms=result["latency_ms"],
        )
    except SimilarSourceUnavailable:
        raise HTTPException(status_code=404, detail="artwork_not_available") from None
    except VisibilityUnavailable:
        raise HTTPException(status_code=503, detail="artwork_visibility_unavailable") from None
    except Exception as exc:
        logger.exception("Similar search failed for artwork %d", body.artwork_id)
        raise HTTPException(status_code=502, detail=str(exc) or type(exc).__name__)


@router.post(
    "/api/v1/rerank",
    response_model=RerankResponse,
    dependencies=[Depends(verify_api_key)],
)
async def rerank(
    body: RerankRequest,
    svc: Annotated[ArtworkService, Depends(get_artwork_service)],
):
    if not settings.rerank_enabled if hasattr(settings, "rerank_enabled") else True:
        return RerankResponse(
            results=[
                SearchResultItem(artwork_id=aid, score=0.0, rank=rank)
                for rank, aid in enumerate(body.artwork_ids, 1)
            ],
            reranked=False,
            model="none (rerank disabled)",
            latency_ms=0,
        )

    return RerankResponse(
        results=[
            SearchResultItem(artwork_id=aid, score=0.0, rank=rank)
            for rank, aid in enumerate(body.artwork_ids, 1)
        ],
        reranked=False,
        model="none",
        latency_ms=0,
    )
