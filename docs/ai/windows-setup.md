# Windows Development Setup

## Prerequisites

- Windows 11
- Open Server Panel 6 (PHP 8.2+, MySQL 8.4)
- Python 3.12+
- Ollama (native Windows)
- Docker Desktop (for Qdrant)

## 1. Ollama Setup

Download and install Ollama from https://ollama.com/download

Pull required models:
```powershell
ollama pull qwen3:8b
ollama pull qwen3-vl:4b
ollama pull qwen3-embedding:0.6b
```

Verify:
```powershell
curl http://127.0.0.1:11434/api/tags
```

## 2. Qdrant Setup (Docker)

```powershell
docker run -d --name qdrant -p 6333:6333 -p 6334:6334 qdrant/qdrant
```

Dashboard: http://127.0.0.1:6333/dashboard

## 3. Python AI Service

```powershell
cd ai-service
py -3.12 -m venv .venv
.venv\Scripts\activate
pip install -e ".[dev]"
copy .env.example .env
```

Start the service:
```powershell
uvicorn app.main:app --host 127.0.0.1 --port 8001 --reload
```

Verify: http://127.0.0.1:8001/health

## 4. Laravel Configuration

Add to `.env`:
```env
AI_SERVICE_ENABLED=true
AI_SERVICE_URL=http://127.0.0.1:8001
```

Run migrations:
```powershell
php artisan migrate
```

Check AI health:
```powershell
php artisan ai:health
```

## 5. Queue Workers

Default queue (media processing):
```powershell
php artisan queue:work --timeout=210
```

AI queue (separate worker recommended):
```powershell
php artisan queue:work --queue=ai --timeout=300
```

## 6. Scheduler

```powershell
php artisan schedule:work
```

## Network Reference

| Service | URL | Notes |
|---------|-----|-------|
| Laravel | http://127.0.0.1:8000 | Via Open Server |
| Python AI | http://127.0.0.1:8001 | Uvicorn |
| Ollama | http://127.0.0.1:11434 | Native |
| Qdrant | http://127.0.0.1:6333 | Docker |
| Qdrant Dashboard | http://127.0.0.1:6333/dashboard | Docker |
| Reverb | ws://localhost:8080 | WebSocket |
