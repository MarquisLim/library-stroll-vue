import hashlib
import json
import sys

import httpx

from app.config import settings

expected = {
    "qwen3:8b": "500a1f067a9f782620b40bee6f7b0c89e17ae61f686b92c24933e4ca4b2b8b41",
    "qwen3-vl:4b-instruct": "84f7ed8042fa7cdbd902f7dcb7d0370e032095047cc3244179f263b77b5bc318",
    "qwen3-embedding:0.6b": "ac6da0dfba84a81fdbfbaf330198c33cd77c4cdfc53e8bc50eb581914a15621d",
}

try:
    assert settings.ollama_chat_model == "qwen3:8b"
    assert settings.ollama_vision_model == "qwen3-vl:4b-instruct"
    assert settings.ollama_embedding_model == "qwen3-embedding:0.6b"
    assert settings.ollama_vision_timeout == 45
    assert settings.ollama_vision_num_predict == 3072
    assert settings.ollama_think is False
    with httpx.Client(base_url=settings.ollama_base_url, timeout=8, trust_env=False) as client:
        version = client.get("/api/version").raise_for_status().json()["version"]
        models = {m["name"]: m["digest"] for m in client.get("/api/tags").raise_for_status().json()["models"]}
        for name, digest in expected.items():
            if models.get(name) != digest:
                raise ValueError("missing or mismatching model " + name)
    with httpx.Client(base_url="http://127.0.0.1:8001", timeout=8, trust_env=False) as client:
        status = client.get("/ready").raise_for_status().json()
        if status["status"] != "ok":
            raise ValueError("AI service is not ready")
    print(json.dumps({"status": "ok", "version": version, "models": expected,
                      "collection": settings.qdrant_collection, "endpoint": settings.ollama_base_url}))
except (httpx.HTTPError, ValueError, AssertionError, KeyError) as exc:
    print(json.dumps({"status": "unavailable_or_mismatched", "error_type": type(exc).__name__}))
    sys.exit(1)
