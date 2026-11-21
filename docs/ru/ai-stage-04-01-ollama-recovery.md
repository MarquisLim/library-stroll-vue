# Этап 4.1.1. Восстановление моделей Ollama

Проверка 21 ноября 2026 г., Windows, ветка `docker`. Исходные результаты приведены в [аудите 4.1](ai-stage-04-01-audit.md).

## Повторная проверка после установки моделей

Снимок 21 ноября 2026 г., около 14:39–14:41 по времени пользователя. Пользователь установил модели. Активный Windows Ollama `0.40.10` использует `B:\models` по журналу запуска и настройке `OLLAMA_MODELS` уровня User. На уровне Process проверяемого PowerShell и Machine переменная отсутствовала. На диске `B:` свободно 194,29 GiB.

| Компонент | Состояние | Результат |
|---|---|---|
| CPU / RAM / GPU | Достаточно для коротких тестов | Ryzen 7 5700X (8 ядер, 16 потоков), 32 GiB RAM, доступно 13,81 GiB. RTX 3060, 12 288 MiB VRAM, свободно 9 814 MiB при исходном замере |
| Ollama | Каталог восстановлен | `ollama list` и `/api/tags` видят `qwen3:4b`, `qwen3:8b`, `qwen3-vl:4b`, `qwen3-embedding:0.6b`. Теги и digest совпадают с каталогом из контейнера. До тестов `ollama ps` был пуст |
| Text LLM | Запрос прошёл | Один короткий запрос `/api/chat` из `ai-api` к настроенной `qwen3:8b`, HTTP 200, `READY` за 2,57 с. `qwen3:4b` проверена по каталогу |
| Vision LLM | Ошибка формата ответа | Один запрос к `/api/v1/artworks/analyze` с иконкой `public/images/favicons/apple.png` (182 × 182), без текстовых метаданных для fallback. `qwen3-vl:4b` загрузилась в GPU. FastAPI вернул 502 через 11,61 с, парсер JSON сообщил `Expecting value: line 1 column 1 (char 0)` |
| Embedding | Запрос прошёл | Один запрос к `/api/v1/embeddings` вернул 1024 значения от `qwen3-embedding:0.6b` за 1,63 с |
| FastAPI | `healthy` | Laravel `ai:health` и `/health` вернули `ok`. `docker/.env` и настройки процесса совпадают по трём ролям и адресу `http://host.docker.internal:11434`. Ошибка Vision не отражается в проверке доступности |
| Qdrant | `green` | `librarystroll_artworks`, до и после тестов 20 точек, 1024, `Cosine`. Счётчик `indexed_vectors_count=0` |
| Redis / Worker | Доступны | Redis `PONG`, `worker-ai` слушает `ai`, 0 ожидающих, зарезервированных и отложенных задач на момент проверки. MySQL по-прежнему показывает 21 `failed` и 0 `indexed` |

| Тест | VRAM модели по `/api/ps` | Общий пик VRAM по `nvidia-smi` |
|---|---:|---:|
| Text | 5 038 MiB | 7 265 MiB |
| Embedding | 1 636 MiB | 3 983 MiB |
| Vision | 4 122 MiB | 9 161 MiB |

Запросы шли последовательно. Во время Vision embedding-модель оставалась загруженной, поэтому общий пик включает обе модели и другие процессы. Готовность каталога восстановлена. К 4.1.2 можно переходить. Для полного сквозного теста 4.2 остаётся разобраться с JSON-ответом Vision и согласовать сценарий сверки и восстановления состояния 20 точек / 21 `failed`. Исходные данные и индексы этой проверкой не изменялись.

Ниже сохранены результаты диагностики до установки моделей.

## 1. Причина и статус

**Причина пустого каталога установлена. Модели пока не восстановлены.** Действующий Windows Ollama использует пустое хранилище `%USERPROFILE%\.ollama\models`. Это следует из записи запуска `server.log`, где указаны путь и `total blobs: 0`. В архивных `server-*.log` указан прежний каталог `B:\models` с 11 blob-файлами. `B:\models` сейчас отсутствует. Когда и почему прежнее хранилище стало недоступно, по имеющимся данным определить нельзя.

## 2. Процесс и фактическое хранилище

| Проверка | Результат |
|---|---|
| Владелец `127.0.0.1:11434` | PID `28384`, `ollama.exe serve`, версия `0.40.10` |
| Способ запуска | Дочерний процесс настольного `ollama app.exe`, PID `27104`. Оба exe в `%LOCALAPPDATA%\Programs\Ollama`. Служба Windows с именем Ollama не обнаружена |
| `OLLAMA_MODELS` для проверенного PowerShell | Process, User и Machine — переменная отсутствует. Путь работающего сервера определён по его журналу запуска |
| Активный путь | `C:\Users\Akitan Gurman\.ollama\models`. В `manifests`, `manifests-v2` и `blobs` по 0 файлов |
| Прежний путь | `B:\models` отсутствует. В доступных метаданных корзины текущего пользователя на `B:` совпадений с этим путём нет |
| Активная работа | `ollama ps` и `/api/ps` показывают 0 загруженных моделей. В очереди `ai` при проверке 0 ожидающих, зарезервированных и отложенных задач |

Поиск имён manifests, `sha256-*` в каталогах blobs и подходящих файлов GGUF на `B:`, `D:` и в доступных каталогах пользователей, приложений и точек монтирования на `C:` совпадений не дал. В доступных стандартных путях уже запущенной Ubuntu WSL нужных manifests и известного embedding blob также не найдено. Поиск ограничен доступными каталогами. Отключённые носители и закрытые папки не проверялись.

## 3. Модели и место для восстановления

| Модель | Назначение | Видна серверу | Размер в реестре Ollama |
|---|---|---|---|
| `qwen3:8b` | Текстовый анализ | Нет | 5,2 GB. [Тег в Ollama](https://ollama.com/library/qwen3:8b) |
| `qwen3-vl:4b` | Анализ изображений | Нет | 3,3 GB. [Тег в Ollama](https://ollama.com/library/qwen3-vl:4b) |
| `qwen3-embedding:0.6b` | Embedding | Нет | 639 MB. [Тег в Ollama](https://ollama.com/library/qwen3-embedding:0.6b) |

Сумма опубликованных размеров около **9,14 GB**. На `C:` в момент проверки было около **50 GB** свободно. Этого достаточно для указанного объёма с запасом. Фактический размер на диске и трафик могут отличаться от округлённых значений реестра.

## 4. Что изменено

Добавлен этот отчёт и обновлён раздел 4.1 в дорожной карте. Настройки Ollama и Docker, файлы моделей, коллекция Qdrant, метаданные работ и датасет остались без изменений. Установленный Ollama и контейнеры не перезапускались. Загрузка моделей ожидает явного согласия, если пользователь не укажет сохранённое хранилище.

## 5. Выполненные команды

- `Get-CimInstance Win32_Process`, `Get-NetTCPConnection`, `netstat -ano -p tcp` для цепочки процессов и владельца порта.
- `[Environment]::GetEnvironmentVariable(...)`, `Get-ChildItem`, `rg --files`, `Get-Content` и `Select-String` для переменных, имён файлов, метаданных каталогов и узкой выборки из журналов. Содержимое весов не читалось.
- `ollama list` и `ollama ps` в Windows. GET `/api/version`, `/api/tags` и `/api/ps` из Windows и `ai-api`. Проверены параметры `OLLAMA_*` из реально загруженных настроек FastAPI.
- `scripts/docker.ps1 status`, `docker inspect` поля healthcheck, GET `/health` и `scripts/docker.ps1 artisan ai:health`.
- Qdrant GET сведений о коллекции и операции чтения `count` и `scroll`. Из MySQL прочитаны агрегаты метаданных и история успешных попыток для найденных точек.

## 6. Проверка Windows и Docker

| Точка проверки | URL | Наблюдение |
|---|---|---|
| Windows | `http://127.0.0.1:11434` | `/api/version` → `0.40.10`, `/api/tags` → HTTP 200, 0 моделей, `/api/ps` → 0 моделей |
| Контейнер `ai-api` | `http://host.docker.internal:11434` | Та же версия, `/api/tags` → HTTP 200, 0 моделей, `/api/ps` → 0 моделей |

GET `/api/tags` из `ai-api` с уникальной меткой появился с той же меткой в `server.log` Windows. Это подтверждает, что оба пути ведут к тому же серверу. В `docker/.env` установлен `DOCKER_OLLAMA_MODE=host`. Настроенные теги совпадают с ожидаемыми для проекта. FastAPI использует таймауты 45 с для Vision, 100 с для Text и общий 180 с, `keep_alive=5m`, `think=false`, `num_predict=768`. Признаков расхождения адресов или имён тегов нет. [Compose](../../compose.yaml#L194), [настройки FastAPI](../../ai-service/app/config.py), [проверка тегов](../../ai-service/app/clients/ollama.py#L66).

## 7. Состояние FastAPI

FastAPI отвечает HTTP 200 на `/health` со статусом `degraded`, `ollama=unavailable`, `qdrant=ok`. Laravel `ai:health` получил тот же результат и завершился кодом 1. Docker показывает `ai-api` как `unhealthy`. Причина в отсутствии обязательных тегов у доступного по HTTP Ollama. Клиент FastAPI требует наличия всех трёх. `/ready` вручную не вызывался. Штатный healthcheck уже настроен в Compose. После появления моделей он должен обновить статус без перезапуска контейнера при доступной коллекции. Это ожидание требует повторной проверки. [Проверка маршрутов](../../ai-service/app/api/routes.py#L79), [Docker healthcheck](../../compose.yaml#L211).

## 8. Совместимость с существующим Qdrant

| Источник | Результат |
|---|---|
| `librarystroll_artworks` | `green`, 20 точек по точному счётчику, 1024, `Cosine`, `indexed_vectors_count=0` |
| MySQL ↔ Qdrant | Для 20 из 20 точек совпадают `qdrant_point_id` и `content_hash`. У соответствующих метаданных присутствует `indexed_at`, сохранена размерность 1024 и имя `qwen3-embedding:0.6b`. Текущий статус этих 20 работ — `failed`. Всего записей `failed` 21 |
| `ai_processing_runs` | 24 успешные попытки `analyze_and_index`, как минимум одна для каждой из 20 работ. В этих успешных записях нет хеша ревизии и имени embedding-модели. Поле `model` хранит модель анализа `qwen3:8b` |
| Payload Qdrant | У точек нет имени и digest embedding-модели |
| Историческая диагностика | Архивные логи Windows показывают загрузку blob с префиксом `06507c7b4268` из `B:\models` и успешные вызовы `/api/embed`. Такой же префикс указан для весов тега `qwen3-embedding:0.6b` в реестре, который также указывает размерность 1024. Документация проекта описывает историческое наличие моделей и успешный тест 1024-мерных embeddings. [Веса в реестре Ollama](https://ollama.com/library/qwen3-embedding:0.6b/blobs/06507c7b4268), [инструкция AI](ai-indexing-setup.md#5-что-проверялось-при-исправлении), [исторический Docker-снимок](docker.md#проверено-24102026) |

Имя модели подтверждено метаданными Laravel и согласуется с историческими логами. Точное происхождение каждого вектора по ревизии весов подтвердить нельзя. Исторический blob не привязан к ID или хешу конкретной точки. Совпадение числа измерений и тега подтверждает ожидаемый формат, а пригодность к записи в общий индекс остаётся отдельной проверкой.

## 9. Что остаётся

Для закрытия 4.1.1 нужны три видимых тега на том же сервере, `ok` в `/health` и `ai:health`, `healthy` в Docker. После восстановления нужно повторить только проверки чтения из следующего раздела. Если `/health` станет `ok`, а Docker продолжит показывать `unhealthy` после очередных циклов проверки, отдельно проверить состояние и коды последних запусков healthcheck у `ai-api`.

К 4.1.2 можно перейти для работы с кодом reconcile и изолированными тестами. Проверка восстановления работ на живом контуре зависит от готовности Ollama. Для закрытия 4.1 остаётся расхождение между 20 точками и 21 `failed`. Переиндексация и изменение AI-метаданных в 4.1.1 не выполнялись.

## 10. Команды и порядок восстановления

**Если найдено прежнее хранилище.** Убедиться, что в его корне есть `manifests` или `manifests-v2` и `blobs`, а требуемые manifests ссылаются на присутствующие blob-файлы. Перед сменой пути проверить `ollama ps`, очередь `ai` и согласовать окно без активных запросов к Ollama. Указать подтверждённый путь в пользовательской переменной `OLLAMA_MODELS`. Закрыть Ollama через меню в системном трее и заново открыть существующее приложение из меню «Пуск». Изменение переменной в одном PowerShell не меняет окружение уже запущенного процесса. [Ollama для Windows](https://docs.ollama.com/windows#changing-model-location).

**Если сохранённой копии нет.** После явного согласия на загрузку использовать текущий Windows Ollama со стандартным хранилищем на `C:`. Перезапуск или смена режима Docker для этого не нужны. В PowerShell направить CLI к уже проверенному слушателю и скачать только требуемые модели.

```powershell
$env:OLLAMA_HOST = 'http://127.0.0.1:11434'
$models = 'qwen3:8b', 'qwen3-vl:4b', 'qwen3-embedding:0.6b'
foreach ($model in $models) {
    ollama pull $model
    if ($LASTEXITCODE -ne 0) { throw "Не удалось загрузить $model" }
}
```

**Проверка чтением после восстановления.** Из корня репозитория в PowerShell.

```powershell
ollama list
ollama ps
(Invoke-RestMethod 'http://127.0.0.1:11434/api/tags' -TimeoutSec 10).models |
    Select-Object name, digest, size
@'
import json
from urllib.request import urlopen
from app.config import settings

with urlopen(settings.ollama_base_url.rstrip('/') + '/api/tags', timeout=8) as response:
    models = {model['name']: model['digest'] for model in json.load(response)['models']}
required = {settings.ollama_chat_model, settings.ollama_vision_model, settings.ollama_embedding_model}
print(json.dumps({name: models.get(name) for name in sorted(required)}, indent=2))
if required - models.keys():
    raise SystemExit('Ollama model tags missing')
'@ | docker compose --env-file docker/.env -f compose.yaml exec -T ai-api python -B -
Invoke-RestMethod 'http://127.0.0.1:8002/health' -TimeoutSec 12
.\scripts\docker.ps1 artisan ai:health
.\scripts\docker.ps1 status
```

Сверить три имени и digest с Windows и Docker. Дождаться, чтобы `ai-api` стал `healthy`. Для дополнительной проверки читать `/health` и состояния Docker, поскольку ручное обращение к `/ready` может инициировать создание коллекции. Загрузка моделей в каталог не проверяет качество или скорость inference.
