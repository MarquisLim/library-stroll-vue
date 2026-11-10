# Демонстрационный каталог Library Stroll

Дата: **10 ноября 2026 года**. Команды: `demo:populate` и `demo:verify`. Они доступны только при фактическом Laravel environment `local` или `testing`; параметра обхода production-ограничения нет.

## Назначение

Каталог предназначен для проверки галереи, фильтров, профилей и исследований. Импортируются настоящие открытые медиаматериалы. Демо-профиль — куратор публикации, а не вымышленный автор музейного произведения. Оригинальный автор, источник, лицензия и атрибуция хранятся в `artwork_imports` и выводятся на странице работы. Биографии отображаются в публичных профилях.

Команда не вызывает Ollama, AI API и Qdrant, не создаёт embeddings и не отправляет `AnalyzeAndIndexArtworkJob`. Демо-работы имеют `is_demo=true`, `ai_indexing_enabled=false`, `has_ai=false`. Проверки в сервисе индексации, reconcile, массовой/ручной индексации и самом задании учитывают запрет. Обычные публикации по умолчанию сохраняют `ai_indexing_enabled=true`.

## Установка

Нужны PHP 8.5 с GD/fileinfo/curl, Node.js 22+, FFmpeg/FFprobe для видео. Из корня проекта:

```powershell
php composer.phar install
npm ci
php artisan migrate
php artisan storage:link
npm run build
```

На Windows при необходимости укажите `FFMPEG_PATH=C:/ffmpeg/bin/ffmpeg.exe`, `FFPROBE_PATH=C:/ffmpeg/bin/ffprobe.exe` в `.env`, затем `php artisan config:clear`. Перезапустите workers после обновления кода: старый worker ещё не знает о запрете индексации демо-работ.

Для Docker пересоберите образ: в PHP-контейнер добавлены Node и только зависимости генерации аватаров из frontend-стадии.

На этапе наполнения задайте `AI_SERVICE_ENABLED=false` в `docker/.env` (для локального запуска — в корневом `.env`), чтобы уже существующие обычные публикации тоже не обрабатывались scheduler во время запуска окружения. Это дополнительное разделение этапов: запрет на демо-работах действует независимо и проверен также при `AI_SERVICE_ENABLED=true`.

```powershell
.\scripts\docker.ps1 up
.\scripts\docker.ps1 artisan demo:populate --images=50 --videos=0 --authors=8 --dry-run
.\scripts\docker.ps1 artisan demo:populate --images=50 --videos=0 --authors=8 --resume
.\scripts\docker.ps1 artisan demo:verify
```

`dry-run` выводит квоты и доступные источники без сетевых запросов, изменений БД, создания аватаров, manifest или файлов. Доступность API и медиа проверяется при настоящем запуске.

## Полное наполнение

```powershell
.\scripts\docker.ps1 artisan demo:populate --preset=balanced --images=600 --videos=20 --authors=45 --seed=42 --batch=25 --resume
.\scripts\docker.ps1 artisan demo:verify
```

Без Docker используйте те же аргументы после `php artisan`. `--source=met,artic,commons` ограничивает источники. `--verify` у `demo:populate` вызывает независимую проверку вместо наполнения.

`--images` и `--videos` — целевой размер созданного набора данного seed/preset, а не число дополнительных строк при каждом повторе. Можно продолжить набор после проверки на 50 изображениях, повысив цель до 600. Старые музейные импорты учитываются как существующие и не переприсваиваются демо-кураторам.

`--authors` допускает 1–100 профилей, `--images` — 0–5000, `--videos` — 0–100, `--batch` — 1–100. Batch задаёт размер страницы кандидатов API и частоту сообщений о прогрессе; загрузка выполняется последовательно. На старте используется файловая блокировка от двух одновременных запусков новой команды.

## Конфигурация

Все подключения и темы находятся в `config/demo_content.php`. Для каждого источника предусмотрены:

```text
sources.<name>
  enabled, base_url, search_endpoint, detail_endpoint
  media_url_template, allowed_media_hosts, api_key
  request_timeout, connect_timeout, rate_limit
  retry_attempts, retry_delay, max_pages, weight, supported_media_types
```

`rate_limit` измеряется в запросах/секунду, `retry_delay` — в миллисекундах. HTTP 403/404 не повторяются автоматически; для 429 и временных серверных/сетевых ошибок предусмотрены ограниченные повторы. `Retry-After` больше 30 секунд останавливает попытку, чтобы не держать команду в длительном ожидании; источник приостанавливается до следующего запуска. Ошибка API приостанавливает источник в текущем запуске; три последовательные ошибки медиа также приостанавливают его. Остальные источники продолжают работу. Автоматическая смена сети или обход защиты отсутствуют.

Переменные в `.env.example`:

```dotenv
DEMO_CONTENT_USER_AGENT="LibraryStrollDemo/1.0 (local research catalog)"
OPENVERSE_ENABLED=false
OPENVERSE_CLIENT_ID=
OPENVERSE_CLIENT_SECRET=
SMITHSONIAN_ENABLED=false
SMITHSONIAN_API_KEY=
```

Укажите свой контакт в User-Agent для публичных запусков. В Docker параметры задаются в `docker/.env`, а не в корневом `.env`. API-ключи и клиентские секреты не должны попадать в Git. `env()` используется только в конфигурации.

### Источники

| Источник | Реализация и правила |
| --- | --- |
| [Met](https://metmuseum.github.io/) | Существующий `MuseumSource`, paginated `/public/collection/v1.1/search`, детали `/public/collection/v1/objects/{id}`. Только `isPublicDomain=true`. |
| [Chicago](https://api.artic.edu/docs/) | Существующий `MuseumSource`, JSON-параметр `params` для поиска, Public Domain и IIIF 843 px. Ключ не нужен, запросы последовательные. |
| [Commons](https://www.mediawiki.org/wiki/API:Imageinfo) | `generator=search`, namespace File, `imageinfo`, `extmetadata`, категории и continuation. Автор и лицензия берутся из конкретного файла. |
| [NASA Images](https://images.nasa.gov/docs/images.nasa.gov_api_docs.pdf) | `/search`, затем `/asset/{nasa_id}`. JPEG/PNG и совместимые MP4. Консервативная проверка NASA/центра в credit; признаки сторонних прав отклоняются. |
| [Openverse](https://api.openverse.org/v1/) | По умолчанию выключен. Поддержаны анонимный поиск и client credentials при заданных ID/secret. В конфигурации необходимо явно заполнить `allowed_media_hosts` доверенными CDN: пустой список запрещает скачивание со случайных внешних доменов. |
| [Smithsonian](https://www.si.edu/openaccess) | По умолчанию выключен. Без ключа пропускается. Принимаются только Images с явно указанным `usage.access=CC0`; недостающие credit/метаданные приводят к пропуску. |

Документация API проверялась перед реализацией. Чтение документации не означает успешного импорта: фактические результаты smoke test фиксируются отдельно. Openverse в окружении просмотра документации ответил 403; старая ссылка Smithsonian devtools ответила 404. Эти два адаптера требуют проверки в вашем окружении с разрешённым доступом и, для Smithsonian, ключом.

### Квоты balanced

| Тема | Изображений |
| --- | ---: |
| Природа и пейзажи | 60 |
| Животные и птицы | 55 |
| Город и архитектура | 50 |
| Портреты и люди | 45 |
| Абстракция и геометрия | 40 |
| Иллюстрация и графика | 45 |
| История и архивы | 35 |
| Наука и технологии | 35 |
| Космос | 30 |
| Транспорт | 30 |
| Морская тематика | 30 |
| Еда и натюрморты | 30 |
| Растения и цветы | 45 |
| Культура и мода | 25 |
| Фантастика и фэнтези | 25 |
| Спорт и движение | 20 |

Для другой цели квоты масштабируются методом наибольших остатков, их сумма совпадает с `--images`. Поисковые запросы и предпочтительные источники заданы у каждой темы. Поиск не добавляет тег: тема допускается по совпадению с названием/метаданными, теги берутся из записи источника и нормализуются `SourceTags` с известными русскими соответствиями. Это консервативная эвристика, а не семантическая классификация; для научной выборки нужна ручная проверка релевантности. Квоты недоступной темы не перераспределяются на нерелевантные изображения. Видео распределяются по доступным темам с общим ограничением `--videos`.

## Происхождение, лицензии и качество

Принимаются явно указанные CC0/Public Domain и CC BY с автором, страницей оригинала, лицензией и атрибуцией. CC BY-SA, NC, ND и неизвестные разрешения не принимаются. NASA не помечается CC0: сохраняется отдельная ссылка на [Media Usage Guidelines](https://www.nasa.gov/nasa-brand-center/images-and-media/). Проверка credit консервативна, но не заменяет ручную правовую проверку выбранного научного набора, особенно для изображений людей и логотипов.

Длинные описания сторонних сайтов не копируются в текст публикации: описание собирается из названия, автора, даты, техники, достоверных тегов, источника и лицензии. Исходное поле описания в DTO/БД предусмотрено отдельно. HTML не исполняется: человекочитаемые метаданные очищаются, Vue выводит их как текст. Отсутствующие сведения не придумываются; записи без автора, названия, тегов или лицензионной ссылки пропускаются.

Изображения: JPEG/PNG/WebP, до 15 MiB и 40 MP. Видео: MP4/WebM, до 100 MiB и 180 секунд, положительная длительность и видеопоток по FFprobe. Лимиты меняются в конфигурации. Нужны HTTPS, точный allowlist хостов, публичный IPv4, запрет редиректов и привязка соединения к проверенному DNS-адресу. Приватные/резервные адреса, credentials в URL и нестандартные порты отклоняются. Крупные файлы скачиваются потоком с контролем размера.

Видео публикуется только после настоящей обработки `ProcessVideoPreviewJob`: превью MP4, миниатюра и длительность. Для коротких видео миниатюра извлекается с начала ролика. Неуспешная обработка откатывает публикацию и удаляет скопированные файлы. Искусственных лайков, комментариев и рейтингов команда не создаёт; просмотры остаются обычными.

## Профили и коллекции

Имена и тематические биографии профилей отделены от авторства материалов. Адреса заканчиваются на `.test`; случайные пароли не печатаются. Аватары генерируются локально в PNG через DiceBear Lorelei и resvg с уникальным seed. [Lorelei имеет CC0 1.0](https://www.dicebear.com/styles/lorelei/); постоянного обращения к CDN аватаров нет.

Профили распределяются по интересам, работы — по теме и хешу ID/seed; одинаковое количество публикаций каждому не назначается. Создаются тематические коллекции и подборки избранного, максимум 24. Пустые темы не получают фиктивные коллекции, поэтому на маленьком или неполном наборе их будет меньше 20. Уже существующие профили, публикации и коллекции не перезаписываются. Для созданной коллекции последующее расширение набора не меняет её состав автоматически: это сохраняет пользовательские правки; новые работы остаются в галерее.

## Повторный запуск и manifest

Manifest: `storage/app/demo-import/manifests/<preset>-<seed>.json`. Он содержит исходные ID, DTO, тему, запрос, ID кураторов/коллекций, результат попытки и целевые квоты. После аварии `--resume` повторяет незавершённые и ошибочные медиазагрузки из сохранённых кандидатов, затем продолжает поиск. Готовые записи не скачиваются снова. Изменившаяся внешняя выдача может добавить других кандидатов; manifest фиксирует именно использованные записи.

Дедупликация выполняется по `(source, external_id)` и SHA-256 между всеми импортами, включая прежние музейные. Для обратной совместимости существующий обязательный `image_sha256` заполнен также хешем видео; универсальное поле `media_sha256` является основным для нового набора. Perceptual hash не реализован: разные перекодировки/обрезки одного изображения могут потребовать ручной дедупликации. Похожие названия не объединяются. Для разнообразия доля одного оригинального автора в теме ограничена `max_creator_share=0.25` (минимум две работы), одного источника при нескольких доступных поставщиках — `max_source_share=0.65`. Пропуски по этим ограничениям имеют статус `diversity`. Цветовая гамма, эпохи и ориентации сохраняют естественное разнообразие источников, но отдельные квоты для них не гарантируются.

## Проверка и отчёты

```powershell
php artisan demo:verify
php artisan demo:populate --verify
```

Проверяются demo-профили и аватары, оригиналы, SHA-256, миниатюры, теги, текст, авторство, лицензии, тематическое распределение, коллекции, видеопревью/длительность, отсутствие AI-метаданных и запусков. Изменение demo-записи пользователем сохраняется, а возникшая неполнота отражается проверкой.

JSON-отчёты находятся в `storage/app/demo-import/reports/`. Manifest и отчёт импорта различают `imported`, `existing`, `duplicate`, `license`, `metadata`, `media_error`; отдельный массив `api_errors` показывает ошибки источников. `shortfall` и `video_shortfall` показывают недобор. Код выхода ненулевой при недоборе; независимая проверка также завершается ошибкой при повреждённых файлах или метаданных. Наличие строк в БД само по себе не считается успехом.

Медиа размещаются через Spatie в `storage/app/public/<media-id>/`, аватары — в `storage/app/public/profile-photos/`, временные загрузки — в `storage/app/artwork-import-tmp/`. Для публикации требуется рабочая ссылка `public/storage` или соответствующий volume Docker. Тестовые API-ответы и локальные видеофикстуры используются только в тестах, не в демонстрационных публикациях.

## Будущая AI-обработка

Сейчас оставьте `ai_indexing_enabled=false`. Позднее оператор сможет явно установить этот признак выбранным ID через отдельный контролируемый этап. После разрешения существующие команды индексации начнут видеть эти работы, а scheduler сможет подхватить их. Не включайте признак массово до готовности к реальным вызовам моделей. Изменение `has_ai` для этого не нужно: это отметка авторского использования AI, а не технический статус анализа. Специальная команда активации AI и семантический поиск этим изменением не добавляются.

## Тесты

```powershell
php artisan test --filter=Demo
php artisan test --filter=ArtworkImportTest
php artisan test
```

Автотесты используют Http/Storage fake, изолированные manifest и БД. Проверка видео создаёт маленький технический fixture через FFmpeg, без внешних API; при отсутствии бинарников тест пропускается. Реальный smoke test выполняется отдельно, максимум на нескольких объектах, без наполнения каталога на 600 файлов.

### Фактическая проверка 10.11.2026

В отдельном SQLite/storage выполнен небольшой импорт: **Met — 1 настоящий файл**, исходник и миниатюра прошли независимую проверку; **Chicago — поисковый API доступен, три IIIF-загрузки вернули HTTP 403**, источник был приостановлен. Созданы 2 локальных PNG-аватара; AI-метаданных и запусков нет. Общий `demo:verify` сообщил недобор одной работы из-за Chicago, поэтому успешное полное наполнение не заявляется. Commons/NASA/Openverse/Smithsonian проверены подменёнными ответами в пределах соответствующих тестов; реальное скачивание с этих источников и реальный внешний видеоисточник не проверялись. Тест видео проверял обработку локального технического fixture, а не права на внешний ролик.

## Файлы реализации

Дополнительно расширен `app/Console/Commands/CheckArtworkImportsCommand.php`: старый проверяющий импортов понимает видео и универсальный SHA-256, сохраняя проверку старых музейных изображений.

Новые файлы:

- `app/Console/Commands/DemoPopulateCommand.php`
- `app/Console/Commands/DemoVerifyCommand.php`
- `app/Services/DemoContent/CommonsAdapter.php`
- `app/Services/DemoContent/ContentItem.php`
- `app/Services/DemoContent/ContentSource.php`
- `app/Services/DemoContent/DemoImporter.php`
- `app/Services/DemoContent/DemoProfiles.php`
- `app/Services/DemoContent/Manifest.php`
- `app/Services/DemoContent/MediaUrlPolicy.php`
- `app/Services/DemoContent/MuseumAdapter.php`
- `app/Services/DemoContent/NasaAdapter.php`
- `app/Services/DemoContent/OpenverseAdapter.php`
- `app/Services/DemoContent/SmithsonianAdapter.php`
- `app/Services/DemoContent/SourceHttp.php`
- `app/Services/DemoContent/SourceRegistry.php`
- `config/demo_content.php`
- `database/migrations/2026_11_10_100000_add_demo_content_fields.php`
- `docs/ru/demo-content.md`
- `scripts/demo-avatars.mjs`
- `tests/Feature/DemoContentTest.php`
- `tests/Feature/DemoSourcesTest.php`

Изменённые файлы:

- `.env.example`
- `README.md`
- `app/Console/Commands/AiIndexArtworkCommand.php`
- `app/Console/Commands/AiReconcileCommand.php`
- `app/Console/Commands/AiReindexAllCommand.php`
- `app/Http/Controllers/Admin/ArtworkController.php`
- `app/Http/Controllers/ArtworkController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Jobs/AnalyzeAndIndexArtworkJob.php`
- `app/Models/Artwork.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/AiService/ArtworkIndexingService.php`
- `app/Services/ArtworkImport/ArtworkImageDownloader.php`
- `app/Services/ArtworkImport/MuseumSource.php`
- `docker/php/Dockerfile`
- `docs/ru/data-import.md`
- `package-lock.json`
- `package.json`
- `resources/js/Pages/Artworks/ArtworksShow.vue`
- `resources/js/Pages/Profile/ProfileShow.vue`
- `tests/Feature/ArtworkImportTest.php`
