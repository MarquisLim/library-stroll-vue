from fastapi import Depends, HTTPException, Request, Security
from fastapi.security import APIKeyHeader

from app.config import settings

api_key_header = APIKeyHeader(name="X-AI-Service-Key", auto_error=False)


async def verify_api_key(
    api_key: str | None = Security(api_key_header),
) -> None:
    if not settings.auth_enabled:
        return
    if api_key != settings.ai_service_api_key:
        raise HTTPException(status_code=401, detail="Invalid or missing API key")
