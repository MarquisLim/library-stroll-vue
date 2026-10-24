# Redis: кэш, очереди и сессии

## Настройка OpenServer

Проверено 24.10.2026: модуль OpenServer Redis запущен, адрес `Redis:6379` разрешается в `127.0.1.25`. PHP `C:/OSPanel/modules/PHP-8.2/php.exe` имеет расширение `redis`. Подключение к `127.0.0.1` на этом компьютере неверно.

Рабочий `.env` переключён на Redis. В `.env.example` оставлен переносимый `REDIS_HOST=127.0.0.1` с подсказкой для OpenServer.

```dotenv
REDIS_CLIENT=phpredis
REDIS_HOST=Redis
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_PREFIX=library_stroll:
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_SESSION_DB=2
REDIS_LOCK_DB=3
REDIS_CACHE_LOCK_CONNECTION=locks
REDIS_CONNECT_TIMEOUT=3
REDIS_READ_TIMEOUT=10
CACHE_STORE=redis
CACHE_PREFIX=cache:
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=900
REDIS_QUEUE_BLOCK_FOR=5
SESSION_DRIVER=redis
SESSION_CONNECTION=sessions
```

Для другого окружения задайте собственный host, пароль, префикс и выделенные номера баз. Здесь проверялся standalone Redis с 16 базами, не Redis Cluster. Не используйте одинаковый префикс для разных установок проекта. `REDIS_URL`, если задан, может переопределять адрес/номер базы default и cache; для этой схемы используйте отдельные переменные выше без `REDIS_URL`. Для отдельных URL sessions/locks предусмотрены `REDIS_SESSIONS_URL` и `REDIS_LOCKS_URL`.

| База | Назначение |
| --- | --- |
| 0 | Очереди Laravel, включая `default` и `ai` |
| 1 | Кэш приложения, прав и служебные cache keys |
| 2 | Сессии пользователей |
| 3 | Атомарные блокировки, уникальные задания и WithoutOverlapping |

Очереди, сессии и блокировки отделены от кэша: `cache:clear` не должен удалять их. Важно: Laravel очищает **всю Redis-базу кэша**, а не только ключи своего префикса. Поэтому DB 1 нельзя совместно использовать как кэш других приложений, даже с другими префиксами. Для диагностики никакая общая очистка не выполняется.

## Проверка

```powershell
Set-Location B:\projects\library-stroll-vue
& C:/OSPanel/modules/PHP-8.2/php.exe artisan config:clear
& C:/OSPanel/modules/PHP-8.2/php.exe artisan redis:check --queue
```

Команда проверяет PING четырёх подключений, запись/чтение/TTL/удаление кэша, взаимное исключение блокировок, сохранение/чтение сессии. С `--queue` ставит настоящее `RedisProbeJob` в уникальную диагностическую очередь и выполняет его через Laravel worker. Обычные очереди не потребляет; после завершения удаляет только свои временные ключи и собственную диагностическую очередь. Временные cache/session/lock keys имеют срок жизни. Без `--queue` очередь не обрабатывается.

## Запуск workers

Два отдельных терминала, из корня проекта:

```powershell
& C:/OSPanel/modules/PHP-8.2/php.exe artisan queue:work redis --queue=default --timeout=600 --tries=3 --sleep=1
& C:/OSPanel/modules/PHP-8.2/php.exe artisan queue:work redis --queue=ai --timeout=600 --tries=3 --sleep=1
```

Это две отдельные долго работающие команды. Обычный worker не должен блокироваться длительной AI-задачей. `composer dev` запускает оба worker вместе с Laravel, Reverb и Vite; не запускайте его одновременно с уже работающими workers, если не хотите дополнительную параллельную обработку. Если изменён `AI_PROCESSING_QUEUE`, замените `ai` в командах и composer script.

После изменения кода/config перезапустите workers; `php artisan queue:restart` просит их завершиться после текущей задачи, но сам новых процессов не создаёт. `queue:work` без менеджера процессов не обеспечивает автозапуск после перезагрузки Windows: используйте OpenServer/Планировщик задач или менеджер процессов. Для остановки вручную запущенных фоновых процессов используйте только записанные PID соответствующих workers.

`retry_after=900` больше максимального timeout AI job (600 секунд), иначе Redis мог бы повторно выдать ещё выполняющуюся задачу другому worker. `read_timeout=10` больше `block_for=5`. Публикация заданий откладывается до commit транзакции, чтобы worker не увидел ещё не сохранённые модели. На Windows отсутствует `pcntl`: timeout worker не является гарантированным жёстким пределом выполнения; сетевые и subprocess timeout должны быть ограничены в самих заданиях.

## Что не переносится в Redis

- MySQL продолжает хранить артворки, коллекции, теги, AI metadata, `failed_jobs` и `job_batches`.
- Qdrant продолжает хранить векторы. Redis не заменяет Ollama, Python API или Qdrant. Для AI нужна отдельная [настройка сервисов](ai-indexing-setup.md); изменение очереди само по себе AI не включает.
- Reverb остаётся WebSocket-транспортом фронтенда, `BROADCAST_CONNECTION=reverb` сохранён. Его queued events идут через Redis-очередь. Redis pub/sub scaling нескольких Reverb-инстансов можно включить отдельно, для одного инстанса не требуется.
- Файлы изображений и видео остаются на настроенном файловом диске.

## Переход с database и сохранность данных

Старые таблицы `jobs`, `cache`, `sessions` не удалялись. Новые задания попадают в Redis; ранее поставленные database jobs автоматически не переносятся. При наличии старых заданий сначала проверьте их состояние и осознанно обработайте отдельным database worker, чтобы не выполнить повторно устаревшие задания. Не запускайте очистку/повтор всех заданий вслепую.

После переключения session driver прежняя авторизация из MySQL-сессии не переносится: пользователю потребуется войти заново. Очистка нового Redis-кэша не очищает старую таблицу кэша.

Проверенный OpenServer Redis использует `maxmemory-policy=noeviction`, RDB snapshots включены, **AOF выключен**. Это подходит для локальной разработки, но при аварии возможна потеря последних поставленных заданий и сессий между snapshot. Глобальная конфигурация OpenServer не менялась. Для надёжного сервера используйте выделенный Redis с AOF (`appendonly yes`, `appendfsync everysec`), резервированием и контролем памяти; `noeviction` предотвращает вытеснение заданий, но при заполнении памяти новые записи будут отклоняться. Настраивайте это через постоянный шаблон/профиль OpenServer, а не только временный CONFIG SET.

Не выполняйте `FLUSHALL`/`FLUSHDB` для устранения ошибок подключения. Они могут удалить очередь и чужие данные.

## Автоматические тесты

Обычные PHPUnit тесты используют array cache/session и sync queue, не требуют Redis. Дополнительно можно включить реальную интеграционную проверку:

```powershell
$env:RUN_REDIS_SMOKE='1'
& C:/OSPanel/modules/PHP-8.2/php.exe -d sys_temp_dir=B:/projects/library-stroll-vue/storage/framework/testing vendor/phpunit/phpunit/phpunit --filter RedisInfrastructureTest --do-not-cache-result
Remove-Item Env:RUN_REDIS_SMOKE
```

Проверка использует уникальные временные ключи, не вызывает flush и не изменяет записи приложения в MySQL.

## Результат проверки на этом компьютере

24.10.2026 выполнены `redis:check --queue` и полный PHPUnit с `RUN_REDIS_SMOKE=1`: 127 тестов, 342 assertions, 7 пропущено, ошибок нет. Оба постоянных worker отдельно получили и выполнили тестовый `RedisProbeJob` через свои очереди, не только через диагностическую очередь. В таблице MySQL `jobs` на момент проверки было 0 записей.

Для текущего сеанса запущены скрытые процессы PHP 8.2: `default` PID **30416**, `ai` PID **29952**. PID действительны только для этого сеанса; после завершения процесса могут быть переиспользованы ОС, поэтому перед остановкой проверяйте командную строку процесса. Логи: `storage/logs/queue-default-20261024-210835.log`, `storage/logs/queue-ai-20261024-210835.log` и соответствующие `.error.log`. Автозапуск при перезагрузке не настраивался.

Эта проверка подтверждает работу Redis-инфраструктуры. Она не подтверждает доступность Python API/Qdrant и не включает `AI_SERVICE_ENABLED`: AI-пайплайн нужно запускать отдельно.
