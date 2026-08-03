from app.config import Settings


def test_default_settings():
    s = Settings(
        _env_file=None,  # type: ignore[call-arg]
    )
    assert s.ai_service_host == "127.0.0.1"
    assert s.ai_service_port == 8001
    assert s.ollama_chat_model == "qwen3:8b"
    assert s.ollama_vision_model == "qwen3-vl:4b"
    assert s.ollama_embedding_model == "qwen3-embedding:0.6b"
    assert s.qdrant_collection == "librarystroll_artworks"


def test_auth_enabled_property():
    s = Settings(ai_service_api_key="my-key", _env_file=None)  # type: ignore[call-arg]
    assert s.auth_enabled is True

    s2 = Settings(ai_service_api_key="", _env_file=None)  # type: ignore[call-arg]
    assert s2.auth_enabled is False
