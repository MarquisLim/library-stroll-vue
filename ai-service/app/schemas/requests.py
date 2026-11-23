from typing import Annotated

from pydantic import AfterValidator, BaseModel, ConfigDict, Field, StringConstraints


def trim_assistant_query(value: str) -> str:
    value = value.strip()
    if not value:
        raise ValueError("Assistant query cannot be blank")
    return value


AssistantQuery = Annotated[str, StringConstraints(min_length=1, max_length=500), AfterValidator(trim_assistant_query)]


class AssistantNormalizeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)

    query: AssistantQuery


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
    artwork_id: int = Field(strict=True, ge=1, le=2**63 - 1)
    limit: int = Field(default=20, ge=1, le=100)
    filters: dict = Field(default_factory=dict)


class RerankRequest(BaseModel):
    query: str
    artwork_ids: list[int]
    limit: int = Field(default=20, ge=1, le=100)
