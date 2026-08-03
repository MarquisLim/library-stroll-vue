# AI Architecture — LibraryStroll

## Overview

```
┌──────────────────┐     HTTP/JSON      ┌────────────────────────┐
│                  │  ──────────────►   │                        │
│   Laravel App    │                    │  Python AI Service     │
│   (PHP 8.2+)     │  ◄──────────────   │  (FastAPI, Python 3.12)│
│                  │                    │                        │
│  - Auth/Users    │                    │  ┌──────────┐          │
│  - Artwork CRUD  │                    │  │  Ollama  │          │
│  - MySQL         │                    │  │ (local)  │          │
│  - Queue jobs    │                    │  └──────────┘          │
│  - Search/UI     │                    │                        │
│                  │                    │  ┌──────────┐          │
└──────────────────┘                    │  │  Qdrant  │          │
                                        │  │ (Docker) │          │
                                        │  └──────────┘          │
                                        └────────────────────────┘
```

## Responsibilities

### Laravel
- User authentication and authorization
- Artwork lifecycle (create, publish, update, delete)
- MySQL database management
- Queue job dispatch and processing
- Final visibility filtering (published, non-private, non-blocked)
- Public API and frontend serving
- AI job orchestration (dispatch, retry, status tracking)

### Python AI Service
- Ollama model interaction (chat, vision, embeddings)
- Structured metadata extraction
- Qdrant vector database management
- Semantic search and similarity queries
- Embedding generation
- Future reranking

### Key Principles
1. **Laravel never connects to Ollama or Qdrant directly**
2. **Python never connects to MySQL or makes authorization decisions**
3. **All AI operations are asynchronous** (except search/similar and health)
4. **Publishing an artwork never waits for AI processing**
5. **All IDs from Python/Qdrant are re-verified via MySQL**
6. **User tags (`artworks.has_ai`) remain independent of AI metadata**

## Data Flow: Artwork Publication

```
1. User publishes artwork via Studio
2. Laravel saves is_published=true, published_at=now()
3. Laravel creates ArtworkAiMetadata (status=pending)
4. Laravel dispatches AnalyzeAndIndexArtworkJob (after commit)
5. HTTP response returns immediately to user
6. Queue worker picks up the job:
   a. Sends image/metadata to Python /api/v1/artworks/analyze
   b. Python calls Ollama (vision/chat) → structured metadata
   c. Laravel sends metadata + text to Python /api/v1/artworks/index
   d. Python generates embedding → upserts to Qdrant
   e. Laravel updates ArtworkAiMetadata (status=indexed)
```

## Data Flow: Search

```
1. User submits search query
2. Laravel executes lexical SQL LIKE search
3. If AI enabled: Laravel calls Python /api/v1/search
4. Python generates query embedding → searches Qdrant
5. Laravel merges results via Reciprocal Rank Fusion
6. Laravel re-filters merged IDs through MySQL (visibility check)
7. Returns combined results to frontend
```
