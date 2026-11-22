# 4.7B — Сквозная проверка ColBERT

## 4.7B-1 — Сквозная проверка создания и изменения документа

Одна публикация `471001` прошла цепочку Laravel → Redis → `worker-ai` → FastAPI → Ollama → Qdrant → MySQL → ColBERT при создании и изменении. После обеих операций `ai:reconcile-colbert --dry-run` вернул `consistent`. Все результаты раздела получены на реальных сервисах и моделях.

### Тестовый контур

Compose-проект `library-stroll-47b-smoke` использует собственные сети и пять томов с префиксом проекта, включая MySQL, Redis, Qdrant, ColBERT и медиа Laravel. База называется `library_stroll_47b_smoke`, очередь — `ai-47b-smoke`, коллекция — `librarystroll_artworks_47b_smoke`. Порты сервисов на хосте не опубликованы. ColBERT работает в сети `library-stroll-47b-smoke_colbert-sync`, к которой подключены Laravel и `worker-ai`. Планировщик не запускался.

Использованы локальные образы Docker. Актуальные Laravel и FastAPI подключены read-only. SHA-256 исходников внутри `app`, `worker-ai` и `ai-api` сверены с веткой `docker` на коммите `4b4d8f7`. В новой базе применены штатные миграции, включая `artwork_colbert_expectations`. Проверены Redis PING, FastAPI `/ready`, пустая коллекция Qdrant и авторизованный GET ColBERT из обоих Laravel-контейнеров. Ключи Laravel, FastAPI и ColBERT передавались через Compose secrets.

FastAPI подтвердил наличие установленных моделей Ollama на Windows. Оба анализа выполнены через Vision `qwen3-vl:4b-instruct`, эмбеддинги рассчитаны моделью `qwen3-embedding:0.6b` и имеют 1024 измерения. ColBERT использовал закреплённую ревизию `a9dc5cd7293d4c71dbbba04829923ba4d0e4f6ea` модели `jinaai/jina-colbert-v2` на RTX 3060. Пик CUDA-памяти при запуске ColBERT составил 2,25 ГиБ. Веса и исходные 100 текстов 4.5A были подключены только для чтения. Тестовая база и Qdrant до публикации были пусты.

### Последовательность и результат

Загружен PNG `public/images/favicons/apple.png` размером 182 × 182. Публикация через `StudioController::publish` поставила одну Dense-задачу в Redis до запуска воркера. После первого прогона название и описание изменены штатным `UpdateArtwork`. Во второй попытке новый вектор заменил предыдущий в той же точке `55848b4c-4b1a-52ae-acdb-4d2d2414a2ab`. В коллекции осталась одна точка. Проверены изменение SHA-256 текста и вектора и соответствие хеша ColBERT расшифрованному тексту в Laravel. Полный текст и шифротекст в артефакты не записывались.

| Операция | Dense | Задача ColBERT | SHA-256 текста, начало | Версия ColBERT | Диагностика |
| --- | --- | ---: | --- | --- | --- |
| Публикация | `indexed`, попытка 1, 15 251 мс | 108,37 мс | `40c4c4ce61e1` | `0136f75f22054dd3bf3558a83fe3f4bf` | `consistent` |
| Правка | `indexed`, попытка 2, 8 134 мс | 82,29 мс | `a135a27155c5` | `3f752c53e2ef4e3091cb6a08134810ef` | `consistent` |
| Повторная синхронизация | Попытка 2 | 9,90 мс | `a135a27155c5` | `3f752c53e2ef4e3091cb6a08134810ef` | `consistent` |

Время Dense взято из `ai_processing_runs`, время ColBERT — из журнала Laravel Queue. Итого завершились две задачи `AnalyzeAndIndexArtworkJob` и три `SyncArtworkColbertJob`. Redis-очередь опустела, в `failed_jobs` осталось 0 записей. Повтор `SyncArtworkColbertJob` прочитал текущий документ и сохранил версию. Отдельный PUT идентичного текста вернул `unchanged`. Счётчик ColBERT остался 102 после обоих повторов, включая 100 исходных представлений. FastAPI зарегистрировал ровно два запроса анализа и два upsert.

Запрос `yellow orange circular illustration on a dark background` вернул `471001` в Hybrid Search в обоих режимах. Dense HTTP 200 вернул ту же работу с оценкой 0,6450517. При включённом флаге ColBERT принял её как кандидата и вернул HTTP 200 и позицию 1. Флаг включался только в процессе проверочного запроса. С фильтром другого автора результат был пуст в обоих режимах. Внутренний API видимости пропустил публикацию и исключил неизвестный ID `471002`. Проверка охватывала одну работу и не измеряла качество ранжирования.

В операциях анализа и индексации ошибок, предупреждений и Text fallback не было. При старте ColBERT в журнале были предупреждение о регулярном выражении токенизатора, диагностическое сообщение загрузки checkpoint и предупреждение об устаревшем API. MySQL сообщала о самоподписанном CA и правах на локальный каталог. Запросы индексации, синхронизации и поиска завершились без 4xx и 5xx.

### Запуск и артефакты

Локальные `compose.smoke.yaml`, `isolated.env`, `with-secrets.sh`, ключи и `probe.php` находятся в `storage/app/ai-stage-04-07/4.7b-1`. Для запуска сохранённого контура из корня репозитория используются следующие аргументы.

```powershell
$stage = 'storage/app/ai-stage-04-07/4.7b-1'
$smoke = @('-p', 'library-stroll-47b-smoke', '--env-file', "$stage/isolated.env",
    '-f', 'compose.yaml', '-f', 'compose.colbert-research.yaml', '-f', "$stage/compose.smoke.yaml")
docker compose @smoke up -d --no-build --pull never --wait app web ai-api colbert
docker compose @smoke exec -T app /bin/sh /stage-47b/with-secrets.sh php artisan ai:reconcile-colbert --dry-run --id=471001
```

В фактическом прогоне `probe.php` запускался из контейнера `app` через `with-secrets.sh`. Фазы шли в порядке `check`, `publish`, запуск `worker-ai`, `snapshot` с `SMOKE_ATTEMPT=1`, `update`, `snapshot` с `SMOKE_ATTEMPT=2`, `search` с выключенным и включённым `AI_RERANK_ENABLED`, `repeat`, `same-put` и финальный `snapshot`. Фаза `publish` проверяет пустоту отдельного контура до создания работы.

`summary.json` содержит полные SHA-256, версии и результаты поиска. `execution-log.json` содержит только времена задач, методы, пути, коды HTTP и категории предупреждений. В отдельных файлах сохранены пробы готовности, инвентарь томов, снимки состояния и три read-only отчёта. Сверены контрольные суммы манифеста 4.4C и трёх исходных файлов 4.5A. Проект остановлен, работа `471001` и все пять томов сохранены для 4.7B-2.
