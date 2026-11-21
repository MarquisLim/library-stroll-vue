import asyncio
from collections.abc import Iterable

import httpx

from app.config import settings


MAX_VISIBILITY_IDS = 101  # Up to 100 candidates plus the source for similar search.
VISIBILITY_PATH = "/api/internal/ai/artwork-visibility"


class VisibilityUnavailable(RuntimeError):
    pass


def is_artwork_id(value: object) -> bool:
    return type(value) is int and 0 < value <= 2**63 - 1


class ArtworkVisibilityClient:
    def __init__(self, http: httpx.AsyncClient):
        self._http = http

    async def visible_ids(self, artwork_ids: Iterable[int]) -> set[int]:
        requested = set(artwork_ids)
        if not requested:
            return set()

        url = settings.laravel_internal_url.strip().rstrip("/")
        key = settings.ai_service_api_key
        if not url or not key.strip() or len(requested) > MAX_VISIBILITY_IDS:
            raise VisibilityUnavailable()

        try:
            async with asyncio.timeout(3):
                response = await self._http.post(
                    url + VISIBILITY_PATH,
                    json={"artwork_ids": sorted(requested)},
                    headers={"Accept": "application/json", "X-AI-Service-Key": key},
                    follow_redirects=False,
                )
                response.raise_for_status()
                body = response.json()
        except (httpx.HTTPError, httpx.InvalidURL, ValueError, TimeoutError):
            raise VisibilityUnavailable() from None

        ids = body.get("visible_ids") if isinstance(body, dict) else None
        if not isinstance(ids, list) or len(ids) > len(requested) or any(
            not is_artwork_id(artwork_id) or artwork_id not in requested for artwork_id in ids
        ):
            raise VisibilityUnavailable()
        return set(ids)
