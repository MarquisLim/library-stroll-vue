# LibraryStroll AI Service

FastAPI service for semantic artwork analysis, embeddings, and vector search.

## Architecture

```
Laravel  →  Python AI Service  →  Ollama (LLM/Vision/Embeddings)
                                →  Qdrant (Vector DB)
```

## Requirements

- Python 3.12
- Ollama running at `http://127.0.0.1:11434` with models:
  - `qwen3:8b` (chat/structured output)
  - `qwen3-vl:4b` (vision analysis)
  - `qwen3-embedding:0.6b` (embeddings)
- Qdrant running at `http://127.0.0.1:6333`

## Windows Setup

```powershell
cd ai-service
py -3.12 -m venv .venv
.venv\Scripts\activate
pip install -e ".[dev]"
```

## Configuration

Copy `.env.example` to `.env` and adjust values if needed:

```powershell
copy .env.example .env
```

## Running

```powershell
uvicorn app.main:app --host 127.0.0.1 --port 8001 --reload
```

## API Docs

Once running, visit:

- Swagger UI: http://127.0.0.1:8001/docs
- ReDoc: http://127.0.0.1:8001/redoc

## Tests

```powershell
pytest -v
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | Service + dependency health |
| POST | `/api/v1/embeddings` | Generate text embeddings |
| POST | `/api/v1/artworks/analyze` | Analyze artwork (multipart) |
| POST | `/api/v1/artworks/index` | Index artwork in Qdrant |
| DELETE | `/api/v1/artworks/{id}/index` | Remove artwork from Qdrant |
| POST | `/api/v1/search` | Semantic search |
| POST | `/api/v1/similar` | Find similar artworks |
| POST | `/api/v1/rerank` | Rerank results (disabled by default) |
