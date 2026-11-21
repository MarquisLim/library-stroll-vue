from hmac import compare_digest

from fastapi import HTTPException, Security
from fastapi.security import APIKeyHeader

from app.config import settings

api_key_header = APIKeyHeader(name="X-AI-Service-Key", auto_error=False)


async def verify_api_key(
    api_key: str | None = Security(api_key_header),
) -> None:
    if not settings.auth_enabled:
        return
    if api_key is None or not compare_digest(api_key.encode(), settings.ai_service_api_key.encode()):
        raise HTTPException(status_code=401, detail="Invalid or missing API key")


async def require_api_key(api_key: str | None = Security(api_key_header)) -> None:
    if not settings.ai_service_api_key.strip():
        raise HTTPException(status_code=401, detail="A configured API key is required")
    await verify_api_key(api_key)
