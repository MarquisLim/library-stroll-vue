from app.config import Settings


def test_default_settings():
    s = Settings(
        _env_file=None,  # type: ignore[call-arg]
    )
    assert s.ai_service_host == "127.0.0.1"
    assert s.ai_service_port == 8001
    assert s.ollama_chat_model == "qwen3:8b"
    assert s.ollama_vision_model == "qwen3-vl:4b-instruct"
    assert s.ollama_embedding_model == "qwen3-embedding:0.6b"
    assert s.qdrant_collection == "librarystroll_artworks"


def test_shared_schema_and_prompt_settings_keep_standalone_aliases(monkeypatch):
    monkeypatch.setenv("METADATA_SCHEMA_VERSION", "2")
    monkeypatch.setenv("PROMPT_VERSION", "3")
    legacy = Settings(_env_file=None)
    assert (legacy.metadata_schema_version, legacy.prompt_version) == (2, 3)

    monkeypatch.setenv("AI_METADATA_SCHEMA_VERSION", "4")
    monkeypatch.setenv("AI_PROMPT_VERSION", "5")
    shared = Settings(_env_file=None)
    assert (shared.metadata_schema_version, shared.prompt_version) == (4, 5)


def test_auth_enabled_property():
    s = Settings(ai_service_api_key="my-key", _env_file=None)  # type: ignore[call-arg]
    assert s.auth_enabled is True

    s2 = Settings(ai_service_api_key="", _env_file=None)  # type: ignore[call-arg]
    assert s2.auth_enabled is False
