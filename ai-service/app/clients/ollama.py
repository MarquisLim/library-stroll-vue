import base64
import json
import logging
import time
from pathlib import Path

import httpx

from app.config import settings
from app.schemas.metadata import ArtworkMetadata

logger = logging.getLogger(__name__)

METADATA_JSON_SCHEMA = {
    "type": "object",
    "properties": {
        "summary": {"type": "string"},
        "objects": {"type": "array", "items": {"type": "string"}},
        "themes": {"type": "array", "items": {"type": "string"}},
        "styles": {"type": "array", "items": {"type": "string"}},
        "mood": {"type": "array", "items": {"type": "string"}},
        "colors": {"type": "array", "items": {"type": "string"}},
        "tags_ru": {"type": "array", "items": {"type": "string"}},
        "tags_en": {"type": "array", "items": {"type": "string"}},
        "confidence": {"type": "number"},
    },
    "required": [
        "summary", "objects", "themes", "styles",
        "mood", "colors", "tags_ru", "tags_en", "confidence",
    ],
}

ANALYSIS_SYSTEM_PROMPT = (
    "You are an artwork metadata extraction system. "
    "Respond ONLY with valid JSON matching the required schema. "
    "Do not include any text before or after the JSON. "
    "Do not use Markdown formatting. "
    "Rules: "
    "- summary: concise description in English (max 200 words). "
    "- objects: real objects visible in the artwork, do not invent unseen objects. "
    "- themes: abstract themes (e.g. loneliness, adventure, nature). "
    "- styles: artistic styles (e.g. watercolor, digital art, photorealism). "
    "- mood: emotional tone (e.g. calm, dramatic, joyful). "
    "- colors: dominant colors. "
    "- tags_ru: up to 15 relevant tags in Russian, no # prefix, natural words. "
    "- tags_en: up to 15 relevant tags in English, no # prefix, natural words. "
    "- confidence: 0.0 to 1.0, how confident you are in the analysis. "
    "- Use 'character' not 'personage'. "
    "- Remove duplicates."
)


class OllamaClient:
    def __init__(
        self,
        base_url: str | None = None,
        timeout: int | None = None,
    ):
        self.base_url = (base_url or settings.ollama_base_url).rstrip("/")
        self.timeout = timeout or settings.ollama_timeout
        self._client = httpx.AsyncClient(
            base_url=self.base_url,
            timeout=httpx.Timeout(self.timeout, connect=10.0),
        )

    async def health(self) -> bool:
        try:
            resp = await self._client.get("/api/tags")
            return resp.status_code == 200
        except httpx.HTTPError:
            return False

    async def list_models(self) -> list[str]:
        resp = await self._client.get("/api/tags")
        resp.raise_for_status()
        data = resp.json()
        return [m["name"] for m in data.get("models", [])]

    async def embed(self, text: str, model: str | None = None) -> list[float]:
        model = model or settings.ollama_embedding_model
        resp = await self._client.post(
            "/api/embed",
            json={
                "model": model,
                "input": text,
                "keep_alive": settings.ollama_keep_alive,
            },
        )
        resp.raise_for_status()
        data = resp.json()
        embeddings = data.get("embeddings", [])
        if not embeddings or not embeddings[0]:
            raise ValueError(f"Ollama returned empty embeddings for model {model}")
        return embeddings[0]

    async def get_embedding_dimension(self, model: str | None = None) -> int:
        vec = await self.embed("dimension probe", model=model)
        return len(vec)

    async def analyze_image(
        self,
        image_bytes: bytes,
        title: str = "",
        description: str = "",
        existing_tags: list[str] | None = None,
    ) -> ArtworkMetadata:
        context_parts = []
        if title:
            context_parts.append(f"Title: {title}")
        if description:
            context_parts.append(f"Description: {description}")
        if existing_tags:
            context_parts.append(f"Existing tags: {', '.join(existing_tags)}")

        user_prompt = "Analyze this artwork image and extract structured metadata."
        if context_parts:
            user_prompt += "\n\nContext:\n" + "\n".join(context_parts)

        image_b64 = base64.b64encode(image_bytes).decode("ascii")

        return await self._chat_structured(
            model=settings.ollama_vision_model,
            system=ANALYSIS_SYSTEM_PROMPT,
            user=user_prompt,
            images=[image_b64],
        )

    async def analyze_text(
        self,
        title: str = "",
        description: str = "",
        existing_tags: list[str] | None = None,
    ) -> ArtworkMetadata:
        parts = []
        if title:
            parts.append(f"Title: {title}")
        if description:
            parts.append(f"Description: {description}")
        if existing_tags:
            parts.append(f"Existing tags: {', '.join(existing_tags)}")

        if not parts:
            raise ValueError("At least title or description is required for text analysis")

        user_prompt = (
            "Based on the following artwork metadata, extract structured metadata:\n\n"
            + "\n".join(parts)
        )

        return await self._chat_structured(
            model=settings.ollama_chat_model,
            system=ANALYSIS_SYSTEM_PROMPT,
            user=user_prompt,
        )

    async def _chat_structured(
        self,
        model: str,
        system: str,
        user: str,
        images: list[str] | None = None,
        max_retries: int = 1,
    ) -> ArtworkMetadata:
        messages = [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ]
        if images:
            messages[-1]["images"] = images

        payload: dict = {
            "model": model,
            "messages": messages,
            "stream": False,
            "format": METADATA_JSON_SCHEMA,
            "keep_alive": settings.ollama_keep_alive,
            "options": {
                "temperature": 0.1,
            },
        }

        last_error: Exception | None = None
        for attempt in range(1 + max_retries):
            try:
                resp = await self._client.post("/api/chat", json=payload)
                resp.raise_for_status()
                data = resp.json()
                content = data.get("message", {}).get("content", "")
                parsed = json.loads(content)
                metadata = ArtworkMetadata.model_validate(parsed)
                return metadata.deduplicated()
            except (json.JSONDecodeError, ValueError) as exc:
                last_error = exc
                logger.warning(
                    "Structured output attempt %d/%d failed: %s",
                    attempt + 1,
                    1 + max_retries,
                    exc,
                )
                continue

        raise ValueError(
            f"Failed to get valid structured output after {1 + max_retries} attempts: {last_error}"
        )

    async def close(self) -> None:
        await self._client.aclose()
