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

    ollama_base_url: str = "http://127.0.0.1:11434"
    ollama_chat_model: str = "qwen3:8b"
    ollama_vision_model: str = "qwen3-vl:4b"
    ollama_embedding_model: str = "qwen3-embedding:0.6b"
    ollama_timeout: int = 180
    ollama_keep_alive: str = "5m"

    qdrant_url: str = "http://127.0.0.1:6333"
    qdrant_api_key: str = ""
    qdrant_collection: str = "librarystroll_artworks"
    qdrant_timeout: int = 30

    metadata_schema_version: int = 1
    prompt_version: int = 1

    @property
    def auth_enabled(self) -> bool:
        return bool(self.ai_service_api_key)


settings = Settings()
