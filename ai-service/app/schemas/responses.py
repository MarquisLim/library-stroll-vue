from pydantic import BaseModel, Field


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


class IndexResponse(BaseModel):
    artwork_id: int
    qdrant_point_id: str
    status: str = "indexed"


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
