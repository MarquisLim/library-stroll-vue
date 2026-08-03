from pydantic import BaseModel, Field


class EmbeddingRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=10000)


class IndexRequest(BaseModel):
    artwork_id: int
    metadata: dict = Field(default_factory=dict)
    searchable_text: str = ""
    payload: dict = Field(default_factory=dict)
    content_hash: str = ""
    idempotency_key: str = ""


class SearchRequest(BaseModel):
    query: str = Field(..., min_length=1, max_length=1000)
    limit: int = Field(default=20, ge=1, le=100)
    filters: dict = Field(default_factory=dict)


class SimilarRequest(BaseModel):
    artwork_id: int
    limit: int = Field(default=20, ge=1, le=100)
    filters: dict = Field(default_factory=dict)


class RerankRequest(BaseModel):
    query: str
    artwork_ids: list[int]
    limit: int = Field(default=20, ge=1, le=100)
