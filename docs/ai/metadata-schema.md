# AI Metadata Schema

Version: 1

## artwork_ai_metadata Table

One-to-one relationship with `artworks`.

| Field | Type | Description |
|-------|------|-------------|
| id | bigint | Primary key |
| artwork_id | bigint | FK → artworks (unique, cascade delete) |
| status | enum | pending, processing, indexed, failed, stale |
| qdrant_point_id | uuid | Qdrant vector point ID |
| content_hash | string(64) | Hash of title+description+media for change detection |
| source_media_uuid | uuid | UUID of the media file analyzed |
| summary | text | AI-generated summary |
| objects | json | Detected objects ["cat", "tree"] |
| themes | json | Abstract themes ["nature", "loneliness"] |
| styles | json | Artistic styles ["watercolor", "digital"] |
| mood | json | Emotional tone ["calm", "dramatic"] |
| colors | json | Dominant colors ["blue", "green"] |
| tags_ru | json | AI-generated tags in Russian |
| tags_en | json | AI-generated tags in English |
| confidence | decimal(4,3) | Model confidence 0.000–1.000 |
| chat_model | string | Ollama chat model used |
| vision_model | string | Ollama vision model used |
| embedding_model | string | Ollama embedding model used |
| embedding_dimension | int | Vector dimension |
| metadata_schema_version | int | Schema version for compatibility |
| prompt_version | int | Prompt version used |
| attempts | int | Processing attempt count |
| last_error | text | Last error message (no stack traces) |
| processed_at | timestamp | When metadata was last extracted |
| indexed_at | timestamp | When indexed in Qdrant |

## Separation of Concerns

- `artworks.has_ai` — manual user flag ("this artwork was made with AI")
- `artwork_ai_metadata.confidence` — AI system confidence in analysis
- `artwork_ai_metadata.tags_*` — AI-extracted tags (separate from user tags)
- `artworks` ← `artwork_tag` → `tags` — user-managed tags

AI tags are never automatically merged into user tags.

## Qdrant Payload

Stored with each vector point:
```json
{
  "artwork_id": 42,
  "is_published": true,
  "is_private": false,
  "is_blocked": false,
  "user_id": 1,
  "media_type": "image",
  "has_ai": false,
  "content_hash": "abc123",
  "schema_version": 1,
  "prompt_version": 1,
  "tags": ["cat", "painting"]
}
```

No sensitive data (emails, file paths) in Qdrant payload.
Embedding vectors are stored only in Qdrant, not in MySQL.
