# Запуск LibraryStroll через Docker

## Быстрый старт на Windows

Нужны Docker Desktop с Linux containers/WSL2, Docker Compose с поддержкой `up --wait` и PowerShell. Локальные PHP, Composer, Node.js, MySQL, Redis и Python для контейнерного приложения не нужны. Docker Desktop должен быть запущен. При ограниченной Execution Policy можно разрешить выполнение только для текущего терминала: `Set-ExecutionPolicy -Scope Process Bypass`.

```powershell
cd B:\projects\library-stroll-vue
.\scripts\docker.ps1 up
.\scripts\docker.ps1 check
```

Первый запуск собирает PHP/Vue/Python, создаёт отдельный `docker/.env` с случайными секретами, поднимает инфраструктуру, применяет миграции и базовые справочники. `APP_KEY` и пароли при последующих запусках не меняются. Секреты не включены в Git и build context. Исходный корневой `.env` и данные OpenServer не меняются.

По умолчанию Ollama остаётся на Windows, с уже установленными моделями и видеокартой. Контейнер обращается к нему по `http://host.docker.internal:11434`. Запустите установленный Ollama; если нужен отдельный терминал:

```powershell
# Если модели хранятся не в стандартном каталоге, укажите их реальный путь.
# На проверяемом компьютере модели найдены в B:\models.
$env:OLLAMA_MODELS='B:\models'
ollama serve
```

Не запускайте второй сервер Ollama, если приложение уже слушает 11434. Для нового компьютера нужны модели из `docker/.env`:

```powershell
ollama pull qwen3:8b
ollama pull qwen3-vl:4b
ollama pull qwen3-embedding:0.6b
```

`check` показывает состояние сервисов, проверяет Redis реальным заданием и выполняет `ai:health`/`ai:status`. Перед массовым импортом убедитесь, что AI health успешен.

## Адреса

| Сервис | С Windows | В сети Compose |
| --- | --- | --- |
| Сайт Nginx → PHP-FPM | http://localhost:8088 | `web:80`, `app:9000` |
| Python AI API | http://localhost:8002/docs | `ai-api:8001` |
| Qdrant | http://localhost:6335/dashboard | `qdrant:6333` |
| Reverb WebSocket | ws://localhost:8081 | `reverb:8080` |
| MySQL | Не опубликован | `mysql:3306` |
| phpMyAdmin | http://localhost:8089 | `phpmyadmin:80` |
| Redis | Не опубликован | `redis:6379` |

Внешние порты слушают только `127.0.0.1`. Они специально отличаются от старых OpenServer/API/Qdrant, поэтому два окружения могут работать параллельно и имеют независимые данные. Можно открыть `http://localhost:8088` или `http://127.0.0.1:8088`: HTTP-ссылки на JS/CSS и маршруты следуют текущему адресу запроса. `APP_URL` остаётся базовым адресом для console/background ссылок и URL медиа. Не смешивайте домен OpenServer и Docker cookies; `localhost` и `127.0.0.1` также имеют раздельные cookie-сессии.

Laravel внутри Docker обращается к AI по `http://ai-api:8001`, не по `127.0.0.1:8002`. Корневой `.env` в образ не копируется. Не копируйте старый `.env` целиком в Docker: адреса сервисов различаются.

## phpMyAdmin: просмотр базы в браузере

Откройте `http://localhost:8089` или `http://127.0.0.1:8089`. Сервер MySQL уже задан как `mysql:3306`; доступен только сервер этого Compose-проекта. Войдите с `DB_USERNAME` и `DB_PASSWORD` из **docker/.env** (не из корневого OpenServer .env). По умолчанию пользователь — `library_stroll`, база — `library_stroll`. Пароль случайный, создаётся скриптом init. Автовход и пустые пароли не включены, пароль не передаётся контейнеру phpMyAdmin. Используйте пользователя приложения, а не root, для повседневного просмотра.

После входа выберите базу слева и таблицы `artworks`, `tags`, `artwork_ai_metadata`, `ai_processing_runs`, `failed_jobs` для просмотра данных и AI-статусов. `jobs` — старая database-очередь: текущие задания находятся в Redis и в phpMyAdmin не отображаются.

phpMyAdmin запускается вместе со всем окружением. Чтобы добавить/запустить только его без пересборки приложения:

```powershell
docker compose --env-file docker/.env -f compose.yaml up -d --wait phpmyadmin
.\scripts\docker.ps1 logs phpmyadmin
```

Адрес привязан только к loopback. MySQL по-прежнему не опубликован наружу. Изменить внешний порт можно через `PHPMYADMIN_PORT` в `docker/.env`, образ — `PHPMYADMIN_IMAGE`. Ничего не импортируется и не очищается при запуске phpMyAdmin. Лимит загрузки SQL через браузер — 64 MiB; большие dump-файлы импортируйте CLI. Изменение/удаление данных в интерфейсе реально влияет на приложение, перед такими операциями делайте backup. Для публичного production не публикуйте эту админку без дополнительной защиты. Настройки образа: [официальная документация phpMyAdmin Docker](https://hub.docker.com/_/phpmyadmin).

## Полностью контейнерный Ollama

CPU (без зависимости от Ollama Windows):

```powershell
.\scripts\docker.ps1 up -Ollama docker
```

NVIDIA GPU:

```powershell
.\scripts\docker.ps1 up -Ollama gpu
```

GPU-вариант требует NVIDIA GPU, актуального совместимого драйвера и поддержки GPU в Docker Desktop/WSL2. На Linux нужен NVIDIA Container Toolkit. AMD/Intel не покрываются NVIDIA override. При отсутствии GPU используйте host или CPU-вариант; CPU-модели могут не укладываться в текущие AI timeout — увеличивайте согласованно timeout Python, Laravel, job и Redis retry_after.

Выбранный режим сохраняется в `DOCKER_OLLAMA_MODE` файла `docker/.env`, поэтому `logs`, `down`, `restart` используют те же Compose-файлы. При переключении режима отдельно остановите ранее запущенный Ollama-контейнер, если он больше не нужен; его том с моделями не удаляйте.

Контейнерный Ollama хранит модели в отдельном named volume, **не использует автоматически модели Windows**. Сервис `ollama-models` загружает три модели перед запуском AI API. Это несколько гигабайт трафика/диска; первый запуск может быть долгим. Модели не скачиваются заново при наличии в томе, но pull проверяет актуальность тега. GPU/CPU-режимы используют общий том моделей. Host-вариант не загружает модели автоматически и не требует контейнера Ollama.

## Ежедневные команды

```powershell
.\scripts\docker.ps1 status
.\scripts\docker.ps1 logs worker-ai ai-api
.\scripts\docker.ps1 logs web app
.\scripts\docker.ps1 restart ai-api worker-ai
.\scripts\docker.ps1 artisan ai:status
.\scripts\docker.ps1 artisan redis:check --queue
.\scripts\docker.ps1 artisan ai:index-artwork 1
.\scripts\docker.ps1 artisan ai:reindex-all
.\scripts\docker.ps1 down
```

`down` сохраняет все named volumes; скрипт не принимает аргументы очистки томов. Остановка worker даёт до 11 минут на завершение текущей задачи. Не выполняйте `docker compose down -v`, `docker volume prune`, `migrate:fresh`, `db:wipe` или Redis flush для обычного перезапуска.

При изменении исходников или `.env` запускайте `up`: он пересобирает образы и пересоздаёт изменившиеся контейнеры. `restart` не подхватывает новые environment variables и не пересобирает код. Исходники по умолчанию не bind-mounted, а frontend собирается Vite в image; live reload не включён. Это исключает проблемы Windows vendor/node_modules и даёт одинаковый код PHP-FPM/worker/Nginx.

Reverb-параметры `VITE_REVERB_HOST`, внешний `REVERB_PORT`, `VITE_REVERB_SCHEME`, `REVERB_APP_KEY` попадают во frontend **во время сборки**. После их изменения нужен `up --build` (скрипт делает это сам). Серверный Laravel использует внутренний `reverb:8080`, браузер — внешний порт. `REVERB_APP_SECRET` не передаётся во frontend.

## Заполнение новой базы

Контейнерная MySQL первоначально пустая: это не копия OpenServer. Автоматический `init` запускает только безопасные миграции и справочники; случайные арты и demo-пользователи не создаются.

Зарегистрируйтесь на сайте или, только для локальной разработки:

```powershell
.\scripts\docker.ps1 artisan data:setup --demo-users
.\scripts\docker.ps1 artisan artworks:import --source=met --limit=20 --publish --index
.\scripts\docker.ps1 artisan artworks:check-imports
.\scripts\docker.ps1 artisan ai:status
```

Demo-пользователи используют известный пароль `password`, не включайте их в публичном окружении. AI индексация асинхронная, следите за `logs worker-ai ai-api`. Журнал старых `failed` попыток сохраняется как история. Для повторной обработки конкретного арта — `ai:index-artwork ID`, всех опубликованных — `ai:reindex-all`. Не совмещайте это с массовым `queue:retry all`.

## Постоянные данные и перенос OpenServer

Тома привязаны к `COMPOSE_PROJECT_NAME`, по умолчанию `library-stroll-docker`:

- `mysql-data`: база приложения, включая AI metadata и failed jobs.
- `app-storage`: загруженные оригиналы, превью и внутренние storage-файлы; общий для Laravel/воркеров, Nginx читает его read-only.
- `redis-data`: очереди, кэш, сессии и locks; AOF `everysec`, eviction выключен.
- `qdrant-data`: векторы.
- `ollama-models`: модели только в контейнерном режиме.

Не меняйте имя проекта или volume без понимания: Docker создаст новые тома, а старые данные останутся в прежних. Redis AOF не заменяет резервные копии и может потерять последнюю секунду записи при аварии. Qdrant — отдельный сервер, не in-memory подмена.

Перенос существующих данных выполняйте отдельно и с резервной копией: экспорт MySQL средствами OpenServer, импорт в контейнер, копирование `storage/app` с сохранением структуры, затем проверка количества записей и изображений. Перед восстановлением остановите web/app/workers/scheduler, чтобы не было параллельных записей; для database restore можно использовать отдельный `docker compose exec mysql` (команды ниже). Не импортируйте SQL в случайно выбранный рабочий сервер и не считайте миграции переносом данных.

При переносе пользователей/зашифрованных данных сохраните **исходный APP_KEY**, иначе 2FA и другие encrypted поля не расшифруются. Запишите его локально в `docker/.env` до запуска восстановленной базы, не публикуйте ключ. `APP_URL` должен соответствовать новому сайту. Если переносите Qdrant, используйте его snapshots; если нет — выполните переиндексацию артворков, чтобы AI metadata соответствовала новому векторному хранилищу.

Ключи/пароли Docker храните отдельно и защищённо. Для резервной копии нужны согласованные MySQL + media, ключ шифрования и Qdrant snapshots либо возможность переиндексации. Копирование активного каталога MySQL не заменяет согласованный SQL dump/backup.

## Обычный Docker Compose без PowerShell-обёртки

Сначала создайте `docker/.env` из примера и заполните уникальные секреты (проще один раз `.\scripts\docker.ps1 init`). Всегда передавайте `--env-file`: иначе Compose может прочитать корневой OpenServer `.env`.

```powershell
docker compose --env-file docker/.env -f compose.yaml up -d --build --wait --wait-timeout 600
docker compose --env-file docker/.env -f compose.yaml exec app php artisan ai:health
docker compose --env-file docker/.env -f compose.yaml exec mysql sh
docker compose --env-file docker/.env -f compose.yaml logs -f worker-ai ai-api
```

Для CPU Ollama добавьте `-f compose.ollama.yaml`, для NVIDIA дополнительно `-f compose.ollama-gpu.yaml`. Эти файлы должны передаваться ко всем командам для соответствующего режима. На Linux можно пользоваться этими же Compose-командами после подготовки файла настроек. Host gateway прописан для доступа к Ollama; на Linux проверьте bind-address/доступность Ollama, не открывайте незащищённый Ollama API в публичную сеть.

## Диагностика

- `init` завершился с ошибкой: смотрите `logs init`, исправьте причину и повторите `up`; миграции не используют `fresh` и не удаляют базу.
- `ai-api` unhealthy / `worker-ai` не стартует: Ollama недоступен, нет нужных моделей, несовместимая размерность Qdrant либо модель ещё загружается. Посмотрите `logs ai-api`, проверьте `http://localhost:8002/ready` и `OLLAMA_BASE_URL`.
- `/health` описывает состояние зависимостей; `/ready` возвращает HTTP 503 до готовности и повторяет первоначальную инициализацию коллекции, если Qdrant/Ollama запустились позже. Ошибка несовместимой размерности не лечится удалением коллекции: выберите новую коллекцию осознанно и переиндексируйте данные.
- Проверки Compose учитывают ready/healthy/completed зависимости, но автоматический restart policy реагирует на **завершение процесса**, не на сам статус unhealthy. При восстановлении зависимости выполните `up` повторно, чтобы запустить ожидающий worker. Runtime-сбои AI задач сохраняются как failed и требуют повторной обработки после восстановления зависимости.
- Порт занят: измените внешний порт в `docker/.env`; для сайта также `APP_URL`, затем `up`. Старые сервисы OpenServer не останавливаются автоматически.
- Неверный пароль после изменения `DB_PASSWORD`: переменные MySQL задают пользователя только при первом создании тома. Верните исходный пароль или измените пользователя SQL-командой; не удаляйте том для сброса пароля.
- Нет изображений после переноса: проверьте наличие оригиналов в `app-storage`, URL диска и `APP_URL`. Симлинк `public/storage` создаётся при сборке образа.

## Тесты и границы окружения

```powershell
.\scripts\docker.ps1 test
.\scripts\docker.ps1 smoke
```

PHPUnit принудительно использует SQLite `:memory:`, array cache/session и sync queue; AI по умолчанию выключен для тестов, а любые не замоканные Laravel HTTP-запросы запрещены. Интеграционный Redis smoke создаёт только собственные временные ключи. Python unit/integration tests не запускают тяжёлую LLM без явного opt-in. Рабочая MySQL при тестах не очищается.

Отдельная команда `smoke` использует **реальный** AI API, embedding-модель Ollama и persistent Qdrant: создаёт два временных вектора со случайными ID, проверяет идемпотентность повторной индексации, русский поиск и similar, удаляет векторы в `finally` и проверяет отсутствие результата. Записи артворков в MySQL не создаются. Это потребляет ресурсы модели и не подменяет генерацию embedding фиктивными данными. Vision/text-анализ этим smoke не проверяется.

### Проверено 24.10.2026

- Сборка PHP 8.2/Vue/Nginx и Python 3.12, первоначальные миграции, повторный `up`, неизменность сгенерированных секретов при повторном `init`.
- Сайт `/`, `/login`, `/up` и собранные JS/CSS отвечают HTTP 200; Reverb отдаёт настоящее WebSocket-событие `pusher:connection_established`.
- MySQL/Redis/AI API healthy; оба постоянных workers обработали отдельные контрольные задания, Redis cache/session/TTL/locks проверены.
- PHPUnit: 128 тестов, 345 assertions, 7 пропущено, ошибок нет. Python: 33 passed, 2 skipped. Тяжёлые проверки пропускаются до явного opt-in.
- Реальный `smoke`: passed; цепочка Laravel → AI API → Ollama → Qdrant также проверена обычным поисковым запросом без фильтров. Исправлена передача пустых filters: JSON `{}`, не `[]`.
- Секретные `.env` отсутствуют в runtime image, контейнерная MySQL осталась без тестовых артворков. Данные OpenServer не переносились и не очищались.
- Host Ollama видит три модели из `B:\models`; CUDA доступна Docker-контейнеру (`cuInit=0`, GPU count=1). Host-режим запущен и проверен полностью. CPU/GPU override-файлы валидированы Compose; отдельная загрузка и выполнение моделей **в контейнерном Ollama** в этой проверке не запускались.

В тестовом выводе есть предупреждение deprecation от Starlette/httpx TestClient, не ошибка выполнения. Обновлять связку тестового клиента следует согласованно с lock-файлом.

Это локальное, не публичное production-окружение: `APP_DEBUG=true`, HTTP без TLS, dev dependencies и demo-команды доступны. Перед публикацией нужны TLS/reverse proxy, закрытые внутренние сервисы, `APP_ENV=production`, `APP_DEBUG=false`, управление секретами/backup и проверка ресурсов/GPU. Версии PHP/MySQL/Redis/Nginx заданы поддерживаемыми ветками, Qdrant — конкретным тегом. Python 3.12 Linux зависимости зафиксированы в `ai-service/requirements.lock` (constraints); qdrant-client 1.18.0 согласован с сервером 1.18.0. При обновлении зависимостей обновляйте constraints и прогоняйте тесты вместе. Для строгой воспроизводимости и контроля цепочки поставки фиксируйте образы digest и проверяйте хэши пакетов. Ollama image можно переопределить через `OLLAMA_IMAGE`.

Основания настройки: [Docker Compose startup order](https://docs.docker.com/compose/how-tos/startup-order/), [доступ к host из Docker Desktop](https://docs.docker.com/desktop/features/networking/networking-how-tos/), [GPU reservations](https://docs.docker.com/compose/how-tos/gpu-support/).
