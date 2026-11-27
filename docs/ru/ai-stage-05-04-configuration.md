# 5.4-0D. Доступность ассистента и конфигурация

В основном `library-stroll-docker` ассистент, RAG, история и контекст включены. Доступ зависит от входа и флага функции. Роль и подтверждение email для маршрутов ассистента и показа меню не требуются. Сверка 5.4-0D подтвердила **511 из 511 актуальных Vision-результатов**. Экспорт всех векторов, payload и настроек коллекции побайтово совпал со снимком 0C3B-R. [Сверка Qdrant](../../storage/app/ai-stage-05-04/5.4-0d/main-qdrant-integrity.json)

## Фактические и рекомендуемые настройки

Compose читает `docker/.env` для окружения контейнеров. Корневой `.env` относится к отдельному запуску Laravel. В нём нет флагов ассистента, RAG, истории и контекста. При загрузке без иных переопределений они выключены. Файл `ai-service/.env` для отдельного FastAPI явно указывает старую модель `qwen3-vl:4b`. В работающем Compose используется `qwen3-vl:4b-instruct`. Для отдельного запуска менять только соответствующий ключ, если этот профиль должен использовать настройки этапа 5.4. [Аудит файлов без секретов](../../storage/app/ai-stage-05-04/5.4-0d/env-audit.json)

| Настройка | Основной контур сейчас | Рекомендация и причина |
|---|---|---|
| `AI_SERVICE_ENABLED`, `AI_ASSISTANT_ENABLED` | `true`, `true` | Сохранить в общем локальном профиле. Включение даёт доступ вошедшим пользователям независимо от роли и подтверждения email. |
| `AI_ASSISTANT_RAG_ENABLED`, `AI_ASSISTANT_HISTORY_ENABLED`, `AI_ASSISTANT_CONTEXT_ENABLED` | Все `true` | Сохранить. История доступна отдельно от RAG. Диалоги требуют RAG и истории, контекст диалога также требует включённый контекст. |
| `AI_ASSISTANT_LLM_ENABLED` | `false` в Laravel и FastAPI | Сохранить текущий поиск. Этот флаг включает дополнительную нормализацию запроса. RAG формирует ответы по отдельному флагу. |
| Модели Ollama | `qwen3:8b`, `qwen3-vl:4b-instruct`, `qwen3-embedding:0.6b` | Сохранить модели и их дайджесты. Замена embedding-модели несовместима с требованием сохранить векторы. |
| Vision и retry | 3072 / 45 с. Один повтор 4096 / 75 с, temperature 0, context 8192. Thinking выключен. | Сохранить проверенный бюджет и явно записать параметры в шаблоне. Первая попытка использует temperature 0.1. Повтор применяется к обрыву и ошибкам структуры ответа. |
| Таймауты анализа и Laravel | Общий анализ 165 с, Laravel HTTP 180 с и соединение 10 с. Индексация допускает два HTTP-вызова с паузой 500 мс. | Сохранить запас между границами FastAPI и Laravel. Compose задаёт 180 с и два вызова. Таймаут задания 600 с больше суммы этих бюджетов. |
| RAG и нормализация | Клиенты Laravel используют величину 15 с, запросы к Ollama для ассистента ограничены 12 с. | Сохранить. Для RAG и фонового анализа разные бюджеты. Увеличение времени ожидания не решает конкуренцию за GPU. |
| Redis | 7.4.11. Очередь `ai`, драйвер очереди, кэша и сессий Redis. `retry_after=900`, `block_for=5`. | Сохранить разделение очередей и `retry_after > 600`. В момент сверки в очереди не было ожидающих, зарезервированных или отложенных заданий. |
| `worker-ai` и `scheduler` | Один `worker-ai`, три попытки Laravel, таймаут 600 с. Scheduler запускает `ai:reconcile --limit=25` раз в 30 минут. Оба сервиса работают. | Оставить один процесс для фоновой AI-очереди на этой GPU. Планировать большие импорты на время низкой активности. |
| Автоиндексация | `AI_AUTO_ANALYZE_ON_PUBLISH=true`, `AI_AUTO_REINDEX_ON_UPDATE=true`. | Сохранить. Обычная публикация ставит задание после коммита. Учитываются флаг AI самой работы, приватность, блокировки работы и автора. |
| Qdrant | `http://qdrant:6333`, `librarystroll_artworks`, 511 точек, 1024 измерения, Cosine, таймаут 30 с. | Сохранить коллекцию, размерность 1024, метрику, версии схемы и промпта 1/1 и модель embedding. Для Laravel используется внутренний адрес `http://ai-api:8001`. |
| Другие поисковые компоненты | Reranking и обе настройки ColBERT Sync выключены. | Сохранить без изменения состава поиска. |
| Квоты | 10 запросов в минуту на аккаунт, общий лимит для поиска, ответа и отправки реплики. 20 диалогов, 100 реплик на диалог, запрос до 500 символов, страница до 20 записей, ссылки на источники до 2048 байт. | Сохранить. Открытие страницы и чтение истории не расходуют лимит поиска. При 429 клиенту следует учитывать `Retry-After`. |
| Параллельность | HTTP-лимит по частоте не является лимитом одновременных вызовов. В приложении нет общего семафора для запросов поиска, RAG и Vision. Ollama 0.40.18 использует общий GPU. | Не увеличивать число `worker-ai`. Для изменений параллельности Ollama сначала измерить задержки и использование памяти под нагрузкой. Фактические `OLLAMA_NUM_PARALLEL` и `OLLAMA_MAX_QUEUE` для хостового процесса надёжно не определены. |

Флаги локального Compose теперь заданы в `docker/.env.example`. Отдельные установки Laravel и FastAPI сохраняют явное включение ассистента после подключения сервисов. Дефолт Vision в FastAPI согласован с Docker. FastAPI теперь принимает те же `AI_METADATA_SCHEMA_VERSION` и `AI_PROMPT_VERSION`, что Laravel, и поддерживает старые имена `METADATA_SCHEMA_VERSION` и `PROMPT_VERSION`. Фактические версии 1/1 сохранены. [Laravel](../../storage/app/ai-stage-05-04/5.4-0d/laravel-config.json) · [FastAPI и дайджесты](../../storage/app/ai-stage-05-04/5.4-0d/fastapi-config.json)

## Блок для docker/.env

Сопоставьте ключи с действующим файлом и изменяйте только нужные строки. Сохраните сгенерированный `AI_SERVICE_API_KEY`. Топологию и жёстко заданные бюджеты Laravel берёт из `compose.yaml`. Этот блок соответствует хостовому Ollama в действующем проекте.

```dotenv
AI_SERVICE_ENABLED=true
AI_ASSISTANT_ENABLED=true
AI_ASSISTANT_LLM_ENABLED=false
AI_ASSISTANT_RAG_ENABLED=true
AI_ASSISTANT_HISTORY_ENABLED=true
AI_ASSISTANT_CONTEXT_ENABLED=true
AI_ASSISTANT_HISTORY_MAX_CONVERSATIONS=20
AI_ASSISTANT_HISTORY_MAX_TURNS=100
AI_ASSISTANT_HISTORY_MAX_QUERY_CHARS=500
AI_ASSISTANT_HISTORY_MAX_REFERENCE_BYTES=2048
AI_ASSISTANT_HISTORY_PAGE_SIZE=20
AI_ASSISTANT_LLM_TIMEOUT=15

AI_SERVICE_CONNECT_TIMEOUT=10
AI_SERVICE_RETRY_SLEEP=500
AI_PROCESSING_QUEUE=ai
AI_METADATA_SCHEMA_VERSION=1
AI_PROMPT_VERSION=1
AI_AUTO_ANALYZE_ON_PUBLISH=true
AI_AUTO_REINDEX_ON_UPDATE=true
AI_RERANK_ENABLED=false
AI_COLBERT_SYNC_ENABLED=false
AI_COLBERT_AUTO_SYNC_ENABLED=false

OLLAMA_BASE_URL=http://host.docker.internal:11434
OLLAMA_CHAT_MODEL=qwen3:8b
OLLAMA_VISION_MODEL=qwen3-vl:4b-instruct
OLLAMA_EMBEDDING_MODEL=qwen3-embedding:0.6b
OLLAMA_VISION_NUM_PREDICT=3072
OLLAMA_VISION_TIMEOUT=45
OLLAMA_VISION_RETRY_NUM_PREDICT=4096
OLLAMA_VISION_RETRY_TIMEOUT=75
OLLAMA_VISION_RETRY_TEMPERATURE=0
OLLAMA_VISION_RETRY_NUM_CTX=8192
ARTWORK_ANALYSIS_TIMEOUT=165
OLLAMA_ASSISTANT_TIMEOUT=12
OLLAMA_TEXT_TIMEOUT=100
OLLAMA_TIMEOUT=180
OLLAMA_KEEP_ALIVE=5m
QDRANT_COLLECTION=librarystroll_artworks
```

Проверки из корня репозитория:

```powershell
.\scripts\docker.ps1 status
.\scripts\docker.ps1 artisan ai:health
.\scripts\docker.ps1 artisan redis:check --queue
ollama ps

$stage = 'storage/app/ai-stage-05-04/5.4-0c3b'
foreach ($phase in @('demo','history')) {
    .\scripts\research\vision-recovery.ps1 -Project library-stroll-docker -Manifest "$stage/$phase-manifest.json" -OutputDir "$stage/$phase-run" -BatchSize 5 -DryRun -VerifyAll
}
```

Работающий контур уже соответствует профилю. При плановом изменении конфигурации дождитесь пустой очереди и примените настройки только к затронутым сервисам. Для перехода на код с общими именами версий достаточно пересобрать FastAPI. Для хостового Ollama используются следующие команды в зависимости от состава изменений:

```powershell
# Пересборка образа FastAPI с согласованными значениями.
docker compose -p library-stroll-docker --env-file docker/.env -f compose.yaml up -d --no-deps --build --wait ai-api
# Применение настроек Laravel после проверки пустой очереди.
docker compose -p library-stroll-docker --env-file docker/.env -f compose.yaml up -d --no-deps --wait app worker-ai scheduler
```

## Проверка доступа и автоиндексации 5.4-0D до изменения политики email

В отдельном `library-stroll-54-config-smoke` с пустыми MySQL, Redis, Qdrant и отдельным томом медиа вход выполнен штатной формой под подтверждённым пользователем с ролью `User`. Пункт меню `AI-ассистент` виден в Edge при 1440×900 и 390×844. Переход открывает `/assistant`. Поиск вернул новую работу и исключил обе ограниченные публикации. Создание диалога и реплики вернуло 201, список и свой диалог — 200. Подтверждённый аккаунт без ролей открывает ассистента и получает 200 в поиске. Чтение и запись в чужой диалог возвращают 404. Гость переходит на вход, неподтверждённый пользователь получает 403 и не видит пункт меню. На 11-м запросе поиска или реплики вернулся 429, страница осталась доступной. [Браузерная проверка](../../storage/app/ai-stage-05-04/5.4-0d/browser-smoke.json) · [Desktop](../../storage/app/ai-stage-05-04/5.4-0d/assistant-desktop.png) · [Мобильная навигация](../../storage/app/ai-stage-05-04/5.4-0d/assistant-mobile.png)

Публикация обычным пользователем через `POST /studio/publish/{id}` добавила одно задание Redis при ещё не запущенном worker. Две контрольные публикации, приватная и с выключенной AI-индексацией, дали ноль заданий. Один изолированный worker обработал очередь. У положительной работы `analysis_source=vision`, `indexed` с первой попытки за 8,55 с на миниатюре 300 px. MySQL и точка Qdrant совпали по ревизии, размерность 1024. В контролях метаданные не появились. После проверок изолированные контейнеры остановлены, их тома сохранены. [Проверка индексации](../../storage/app/ai-stage-05-04/5.4-0d/auto-index-smoke.json)

В одном замере обычный прогретый поиск занял 47–50 мс, вызов рядом со стартом worker — 95 мс. Отдельно проверен реальный RAG одновременно с Vision без записи индекса. Прогретый RAG занял 2,28 с, параллельный — 7,01 с, Vision — 8,45 с. Оба вызова завершились успешно. В этих пробах на RTX 3060 использовалось до 11 676 из 12 288 MiB. Ollama показала одновременно загруженные три модели. Это подтверждает запас памяти и задержки только для проверенного сценария. Для оценки максимальной параллельности и перцентилей нужен отдельный нагрузочный прогон. [Снимки при поиске](../../storage/app/ai-stage-05-04/5.4-0d/browser-smoke.json) · [Замер RAG и Vision](../../storage/app/ai-stage-05-04/5.4-0d/rag-contention.json)

Автоматическая проверка завершилась с 182 успешными профильными тестами Laravel и 191 успешным Python-тестом, два Python-теста пропущены. Изменения этапа относятся к шаблонам настроек и значениям по умолчанию. Обе группы работ основного каталога проверены только для чтения. [Laravel](../../storage/app/ai-stage-05-04/5.4-0d/laravel-access-tests.txt) · [Python](../../storage/app/ai-stage-05-04/5.4-0d/python-config-tests.txt) · [491 демо](../../storage/app/ai-stage-05-04/5.4-0d/main-demo-verified.json) · [20 исторических](../../storage/app/ai-stage-05-04/5.4-0d/main-history-verified.json)

## Доступ без подтверждения email

После проверки 5.4-0D условие подтверждённого email снято только с маршрутов ассистента. Меню теперь зависит от входа и флага `AI_ASSISTANT_ENABLED`. Сохранены веб-сессия, CSRF, квота 10 запросов в минуту, изоляция диалогов и проверка видимости работ. Middleware подтверждения email на остальных страницах сохранён.

Под неподтверждённым тестовым аккаунтом без ролей Edge показал desktop и мобильное меню. Страница, поиск, ответ, превью и история вернули 200, создание диалога и реплики — 201. Гость получил 401 с действительным CSRF-токеном и перенаправление на вход при открытии страницы. Чужой диалог и приватное превью вернули 404, превышение квоты — 429. Прошли 143 профильных теста Laravel. [Проверка браузера](../../storage/app/ai-stage-05-04/5.4-0d/assistant-session-browser.json) · [Тесты Laravel](../../storage/app/ai-stage-05-04/5.4-0d/assistant-session-tests.txt)

Обновлён только Laravel-контейнер основного проекта. В действующем приложении серверное условие показа меню возвращает `true` для проверенного неподтверждённого аккаунта. Действующие настройки сохранены. Снимок 511 точек Qdrant побайтово совпал со снимком до обновления. [Проверка основного контура](../../storage/app/ai-stage-05-04/5.4-0d/assistant-session-main.json)
