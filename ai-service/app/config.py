from pydantic import AliasChoices, Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    ai_service_host: str = "127.0.0.1"
    ai_service_port: int = 8001
    ai_service_api_key: str = ""
    laravel_internal_url: str = ""
    ai_assistant_enabled: bool = False
    ai_assistant_llm_enabled: bool = False
    ai_assistant_rag_enabled: bool = False
    ai_assistant_context_enabled: bool = False

    ollama_base_url: str = "http://127.0.0.1:11434"
    ollama_chat_model: str = "qwen3:8b"
    ollama_vision_model: str = "qwen3-vl:4b-instruct"
    ollama_embedding_model: str = "qwen3-embedding:0.6b"
    ollama_timeout: int = 180
    ollama_keep_alive: str = "5m"
    ollama_think: bool = False
    ollama_num_predict: int = 768
    ollama_vision_num_predict: int = Field(default=3072, gt=0)
    ollama_vision_timeout: float = Field(default=45, gt=0, le=45)
    ollama_vision_retry_num_predict: int = Field(default=4096, gt=0, le=4096)
    ollama_vision_retry_timeout: float = Field(default=75, gt=0, le=75)
    ollama_vision_retry_temperature: float = Field(default=0, ge=0, le=1)
    ollama_vision_retry_num_ctx: int = Field(default=8192, ge=4096)
    artwork_analysis_timeout: float = Field(default=165, gt=0, le=165)
    ollama_text_timeout: int = 100
    ollama_assistant_timeout: float = Field(default=12, gt=0, le=30)

    qdrant_url: str = "http://127.0.0.1:6333"
    qdrant_api_key: str = ""
    qdrant_collection: str = "librarystroll_artworks"
    qdrant_timeout: int = 30

    metadata_schema_version: int = Field(
        default=1, validation_alias=AliasChoices("AI_METADATA_SCHEMA_VERSION", "metadata_schema_version")
    )
    prompt_version: int = Field(
        default=1, validation_alias=AliasChoices("AI_PROMPT_VERSION", "prompt_version")
    )

    @property
    def auth_enabled(self) -> bool:
        return bool(self.ai_service_api_key)


settings = Settings()
