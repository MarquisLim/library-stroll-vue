from typing import Literal

from pydantic import BaseModel, Field, StrictBool, StrictInt, StrictStr


class HealthServiceStatus(BaseModel):
    ollama: str = "unknown"
    qdrant: str = "unknown"


class HealthResponse(BaseModel):
    status: str = "ok"
    services: HealthServiceStatus = Field(default_factory=HealthServiceStatus)


class EmbeddingResponse(BaseModel):
    model: str
    dimensions: int
    embedding: list[float]


class AnalyzeResponse(BaseModel):
    artwork_id: int
    summary: str = ""
    objects: list[str] = Field(default_factory=list)
    themes: list[str] = Field(default_factory=list)
    styles: list[str] = Field(default_factory=list)
    mood: list[str] = Field(default_factory=list)
    colors: list[str] = Field(default_factory=list)
    tags_ru: list[str] = Field(default_factory=list)
    tags_en: list[str] = Field(default_factory=list)
    confidence: float = 0.0
    chat_model: str = ""
    vision_model: str = ""
    embedding_model: str = ""
    latency_ms: int = 0
    analysis_source: str = "text"
    warnings: list[str] = Field(default_factory=list)


class IndexResponse(BaseModel):
    artwork_id: int
    qdrant_point_id: str
    status: str = "indexed"
    embedding_dimension: int = 0


class ArtworkIndexPoint(BaseModel):
    id: str
    artwork_id: StrictInt | None = None
    content_hash: StrictStr | None = None
    schema_version: StrictInt | None = None
    prompt_version: StrictInt | None = None
    is_published: StrictBool | None = None
    is_private: StrictBool | None = None
    is_blocked: StrictBool | None = None
    embedding_model: StrictStr | None = None
    embedding_model_digest: StrictStr | None = None
    embedding_fingerprint: StrictStr | None = None


class ArtworkIndexInspection(BaseModel):
    artwork_id: int
    collection: str
    dimension: int | None
    saved_point: Literal["not_provided", "matched", "missing", "conflict"]
    has_duplicates: bool
    vector_present: bool | None
    points: list[ArtworkIndexPoint] = Field(max_length=2)


class DeleteIndexResponse(BaseModel):
    artwork_id: int
    status: str = "deleted"


class SearchResultItem(BaseModel):
    artwork_id: int
    score: float
    rank: int


class SearchResponse(BaseModel):
    results: list[SearchResultItem] = Field(default_factory=list)
    model: str = ""
    latency_ms: int = 0


class SimilarResponse(BaseModel):
    results: list[SearchResultItem] = Field(default_factory=list)
    model: str = ""
    latency_ms: int = 0


class RerankResponse(BaseModel):
    results: list[SearchResultItem] = Field(default_factory=list)
    reranked: bool = False
    model: str = ""
    latency_ms: int = 0
