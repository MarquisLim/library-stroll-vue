# AI Service API Contract

Base URL: `http://127.0.0.1:8001`

## Authentication

If `AI_SERVICE_API_KEY` is set, all endpoints (except `/health`) require:
```
Header: X-AI-Service-Key: <key>
```

## Endpoints

### GET /health

Health check for the service and its dependencies.

**Response:**
```json
{
  "status": "ok",
  "services": {
    "ollama": "ok",
    "qdrant": "ok"
  }
}
```

### POST /api/v1/embeddings

Generate text embeddings.

**Request:**
```json
{
  "text": "a painting of a sunset over the ocean"
}
```

**Response:**
```json
{
  "model": "qwen3-embedding:0.6b",
  "dimensions": 1024,
  "embedding": [0.1, 0.2, ...]
}
```

### POST /api/v1/artworks/analyze

Analyze artwork image/metadata to extract structured metadata.

**Request:** `multipart/form-data`
- `artwork_id` (int, required)
- `title` (string)
- `description` (string)
- `existing_tags` (comma-separated string)
- `image` (file, optional — thumbnail or preview frame)

**Response:**
```json
{
  "artwork_id": 42,
  "summary": "A watercolor painting of a cat...",
  "objects": ["cat", "window"],
  "themes": ["animals", "domestic life"],
  "styles": ["watercolor"],
  "mood": ["calm"],
  "colors": ["orange", "white"],
  "tags_ru": ["кот", "акварель"],
  "tags_en": ["cat", "watercolor"],
  "confidence": 0.85,
  "chat_model": "qwen3:8b",
  "vision_model": "qwen3-vl:4b",
  "embedding_model": "qwen3-embedding:0.6b",
  "latency_ms": 3200
}
```

### POST /api/v1/artworks/index

Index artwork in Qdrant vector database.

**Request:**
```json
{
  "artwork_id": 42,
  "metadata": {},
  "searchable_text": "cat watercolor painting",
  "payload": {
    "artwork_id": 42,
    "is_published": true,
    "user_id": 1,
    "media_type": "image"
  },
  "content_hash": "abc123",
  "idempotency_key": "existing-point-id"
}
```

**Response:**
```json
{
  "artwork_id": 42,
  "qdrant_point_id": "uuid",
  "status": "indexed"
}
```

### DELETE /api/v1/artworks/{artwork_id}/index

Remove artwork from Qdrant. Idempotent.

### POST /api/v1/search

Semantic search.

**Request:**
```json
{
  "query": "sunset over ocean",
  "limit": 20,
  "filters": {}
}
```

**Response:**
```json
{
  "results": [
    {"artwork_id": 1, "score": 0.95, "rank": 1},
    {"artwork_id": 5, "score": 0.80, "rank": 2}
  ],
  "model": "qwen3-embedding:0.6b",
  "latency_ms": 150
}
```

### POST /api/v1/similar

Find similar artworks by artwork ID.

### POST /api/v1/rerank

Rerank results (disabled by default, returns original order).
