from pydantic import BaseModel, Field


class ArtworkMetadata(BaseModel):
    """Structured metadata extracted by LLM vision/chat analysis."""

    summary: str = Field(default="", max_length=1000)
    objects: list[str] = Field(default_factory=list)
    themes: list[str] = Field(default_factory=list)
    styles: list[str] = Field(default_factory=list)
    mood: list[str] = Field(default_factory=list)
    colors: list[str] = Field(default_factory=list)
    tags_ru: list[str] = Field(default_factory=list)
    tags_en: list[str] = Field(default_factory=list)
    confidence: float = Field(default=0.0, ge=0.0, le=1.0)

    def deduplicated(self) -> "ArtworkMetadata":
        """Return a copy with duplicate items removed from all list fields."""

        def _dedup(items: list[str], limit: int = 15) -> list[str]:
            seen: set[str] = set()
            result: list[str] = []
            for item in items:
                cleaned = item.strip().lstrip("#")
                lower = cleaned.lower()
                if lower and lower not in seen:
                    seen.add(lower)
                    result.append(cleaned)
                if len(result) >= limit:
                    break
            return result

        return ArtworkMetadata(
            summary=self.summary.strip(),
            objects=_dedup(self.objects),
            themes=_dedup(self.themes),
            styles=_dedup(self.styles),
            mood=_dedup(self.mood),
            colors=_dedup(self.colors),
            tags_ru=_dedup(self.tags_ru),
            tags_en=_dedup(self.tags_en),
            confidence=self.confidence,
        )
