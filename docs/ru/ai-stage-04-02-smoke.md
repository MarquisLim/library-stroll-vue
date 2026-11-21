# 4.2A. Первый сквозной AI-тест

21 ноября 2026 г. в ветке `docker` на коммите `ce8e9cb` проверен маршрут Laravel → Redis → `worker-ai` → FastAPI → Ollama → Qdrant → MySQL → семантический поиск. Создана одна опубликованная тестовая работа с изображением [apple.png](../../public/images/favicons/apple.png) 182 × 182. Media Library создала миниатюру WebP 300 × 300, которую прочитал worker.

## Изоляция

Проект `library-stroll-42a-c1fa67cf` использовал собственную сеть, свежие тома MySQL, Redis, Qdrant и медиа, отдельную очередь `ai-42a` и порты localhost 50401, 50402 и 50403 для сайта, FastAPI и Qdrant. Перед миграциями и созданием коллекции проверены фактические сети и монтирования контейнеров. Код текущей ветки смонтирован только для чтения. Контрольные суммы файлов зависимостей совпали с локальными образами. Общим с рабочим окружением был только Ollama на Windows. Scheduler, сидеры и рабочие хранилища в тесте не участвовали.

## Результат

| Проверка | Итог |
|---|---|
| Публикация | ID `420001`. Работа публична, автор доступен, `ai_indexing_enabled=true`. Маршрут проверки видимости вернул HTTP 200 и разрешил этот ID |
| Redis и worker | До постановки очередь пуста, после команды в ней ровно одно задание. Worker завершил его за 38,44 с. После завершения готовых, зарезервированных и отложенных заданий нет |
| Vision | `qwen3-vl:4b`, источник `vision`. FastAPI вернул HTTP 200, сохранено описание, предупреждений нет |
| Embedding | `qwen3-embedding:0.6b`, размерность 1024 |
| Qdrant | Коллекция `librarystroll_artworks_42a_c1fa67cf`, метрика Cosine. Точек до запуска 0, после 1. ID точки `8a0d310d-f0ff-5eb2-80e4-e8d093a32e2b`. Зафиксировано наличие вектора, ID и хеш содержимого совпадают с MySQL |
| MySQL | Статус `indexed` выставлен worker, попыток 1. В `ai_processing_runs` запись № 1 со статусом `success` |
| Поиск | Запрос `glowing yellow sun with a dark background`. FastAPI вернул HTTP 200, ID `420001`, ранг 1, score 0,5750371. Laravel Hybrid Search вернул тот же ID при нуле лексических совпадений |

Ошибок при обработке и поиске не обнаружено. Код приложения в этом подэтапе не менялся.

## Ключевые команды

```powershell
$smoke = Join-Path $env:TEMP 'library-stroll-42a-c1fa67cf'
$compose = @('--project-name', 'library-stroll-42a-c1fa67cf', '--env-file',
    (Join-Path $smoke 'smoke.env'), '-f', 'compose.yaml', '-f', (Join-Path $smoke 'compose.smoke.yaml'))
$resolved = docker compose @compose config --format json
docker compose @compose up -d --no-build --pull never --wait --wait-timeout 150 mysql redis qdrant
docker compose @compose up -d --no-build --pull never --wait --wait-timeout 200 web ai-api
Get-Content -Raw (Join-Path $smoke 'seed.php') | docker compose @compose exec -T app php
docker compose @compose exec -T app php artisan ai:index-artwork 420001
docker compose @compose up -d --no-deps --no-build --pull never worker-ai
Get-Content -Raw (Join-Path $smoke 'poll.php') | docker compose @compose exec -T app php
Get-Content -Raw (Join-Path $smoke 'search.php') | docker compose @compose exec -T app php
docker compose @compose stop --timeout 15 worker-ai web ai-api app redis mysql qdrant
```

Тестовый проект остановлен, четыре тома сохранены. Журналы `runtime.log`, `poll.log`, `search.log` и `storage.log` сохранены в `%TEMP%/library-stroll-42a-c1fa67cf`. Рабочие 20 точек и записи ID 1–21 не затронуты. К контролируемой индексации 10–20 изображений можно переходить в тестовом окружении.
