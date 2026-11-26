import json
import os
import socket
import sys
from urllib.parse import urlsplit

import httpx

from app.config import settings

expected = {
    "qwen3:8b": "500a1f067a9f782620b40bee6f7b0c89e17ae61f686b92c24933e4ca4b2b8b41",
    "qwen3-vl:4b-instruct": "84f7ed8042fa7cdbd902f7dcb7d0370e032095047cc3244179f263b77b5bc318",
    "qwen3-embedding:0.6b": "ac6da0dfba84a81fdbfbaf330198c33cd77c4cdfc53e8bc50eb581914a15621d",
}

stage = "qdrant_topology"
try:
    target = json.loads(os.environ["RECOVERY_TARGET"])
    endpoint = urlsplit(settings.qdrant_url)
    if (endpoint.scheme != "http" or endpoint.port != 6333 or endpoint.username is not None
            or endpoint.query or endpoint.fragment or endpoint.path not in ("", "/")
            or set(socket.gethostbyname_ex(endpoint.hostname)[2]) != {target["qdrant"]}):
        raise ValueError("Qdrant endpoint does not match selected Compose service")
    if target["collection"] and settings.qdrant_collection != target["collection"]:
        raise ValueError("Qdrant collection does not match manifest")
    stage = "qdrant_collection"
    with httpx.Client(base_url=settings.qdrant_url, headers={"api-key": settings.qdrant_api_key},
                      timeout=8, trust_env=False) as client:
        collection = client.get("/collections/" + settings.qdrant_collection).raise_for_status().json()["result"]
    vectors = collection["config"]["params"]["vectors"]
    if vectors.get("size") != 1024 or vectors.get("distance") != "Cosine":
        raise ValueError("Unexpected Qdrant vector configuration")
    report = {"collection": settings.qdrant_collection, "qdrant_url": settings.qdrant_url,
              "qdrant_points": collection["points_count"], "vectors": vectors,
              "schema_version": settings.metadata_schema_version, "prompt_version": settings.prompt_version,
              "vision_model": settings.ollama_vision_model}
    stage = "models"
    with httpx.Client(base_url=settings.ollama_base_url, timeout=8, trust_env=False) as client:
        version = client.get("/api/version").raise_for_status().json()["version"]
        models = {m["name"]: m["digest"] for m in client.get("/api/tags").raise_for_status().json()["models"]}
        mismatches = [name for name, digest in expected.items() if models.get(name) != digest]
    config_ok = (
        settings.ollama_chat_model == "qwen3:8b"
        and settings.ollama_vision_model == "qwen3-vl:4b-instruct"
        and settings.ollama_embedding_model == "qwen3-embedding:0.6b"
        and settings.ollama_vision_timeout == 45
        and settings.ollama_vision_num_predict == 3072
        and settings.ollama_think is False
    )
    stage = "ready"
    with httpx.Client(base_url="http://127.0.0.1:8001", timeout=8, trust_env=False) as client:
        status = client.get("/ready").raise_for_status().json()
        if status["status"] != "ok":
            raise ValueError("AI service is not ready")
    print(json.dumps({**report, "status": "ok" if config_ok and not mismatches else "config_mismatch",
                      "ollama_version": version, "models": {name: models.get(name) for name in expected},
                      "digest_mismatches": mismatches}))
except (httpx.HTTPError, ValueError, KeyError, OSError) as exc:
    print(json.dumps({"status": "unavailable_or_mismatched", "stage": stage, "error_type": type(exc).__name__}))
    sys.exit(1)
