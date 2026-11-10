# LibraryStroll

Демонстрационный медиакаталог без AI-обработки: [demo:populate и demo:verify](docs/ru/demo-content.md) (10.11.2026).

Проект использует **Laravel 13, PHP 8.5, Inertia 2 и Vue 3**. Версии зависимостей закреплены в `composer.lock` и `package-lock.json`; для установки используйте `install` / `npm ci`, а не повторное обновление пакетов.

Что изменилось и как пользоваться обновлением от **02.11.2026**: [обновление проекта, студия, администрация и мониторинг AI](docs/ru/2026-11-02-project-update-and-usage.md).

Docker (PowerShell, из корня проекта):

```powershell
.\scripts\docker.ps1 up
.\scripts\docker.ps1 check
```

Сайт: http://localhost:8088. phpMyAdmin: http://localhost:8089 (логин и пароль из `docker/.env`). Требуется запущенный Docker Desktop (Linux containers).
По умолчанию используется Ollama на Windows; для полного Docker-запуска: `.\scripts\docker.ps1 up -Ollama gpu` (NVIDIA) или `-Ollama docker` (CPU).
Подробности, команды и сохранность данных: [Docker-инструкция](docs/ru/docker.md).

## Локальный запуск после обновления

В OpenServer выберите PHP 8.5 для сайта и перезапустите его. CLI тоже должен использовать PHP 8.5: `.php-version` служит подсказкой для менеджеров версий, но не переключает PHP в PATH или настройках OpenServer автоматически. Для уже установленного в этом окружении PHP можно выполнить в текущем PowerShell:

```powershell
$env:PATH = 'C:\OSPanel\modules\PHP-8.5;' + $env:PATH
php -v
php composer.phar install
php composer.phar check-platform-reqs
npm ci
npm run build
php artisan config:clear
php artisan migrate
php artisan test
```

Перед обновлением рабочего сервера сделайте резервную копию базы. После установки перезапустите очереди (`php artisan queue:restart`) и Reverb. Для разработки: `php composer.phar dev`. В репозитории обновлён `composer.phar` для PHP 8.5; при использовании глобального Composer требуется актуальная версия Composer 2.

Тесты используют отдельную SQLite-базу в памяти и подменяют внешние HTTP-запросы. Проверка настоящего Redis выполняется отдельно через `RUN_REDIS_SMOKE=1` или Docker-команду `scripts/docker.ps1 test`. Настройки префиксов Redis/cache и формат существующих PHP-сессий сохранены. Кэш главной страницы хранит только ID; приватность, лайки и коллекции проверяются заново при каждом запросе.

Локальные файлы диска `public` (работы и загруженные аватары, включая header) в HTTP-ответах используют адреса `/storage/...` от текущего источника страницы. Поэтому открытие сайта через `127.0.0.1` при `APP_URL=http://localhost:8088` не требует разрешать CORS между этими адресами. `APP_URL` остаётся базовым адресом для консольных задач. Для отдельного CDN можно явно задать `PUBLIC_STORAGE_URL`; его адрес сохраняется. После изменения исходников Docker нужно пересобрать: работающие контейнеры не подхватывают локальный Vue/PHP-код автоматически.

## Мониторинг LLM и векторного индекса

Для Admin и SuperAdmin доступен раздел **Администрирование → AI и векторы** (`/admin/ai`). Он показывает статусы опубликованных работ, этапы активных запусков, последние 20 попыток, ошибки, модели Ollama, состояние коллекции Qdrant и счётчики очереди. Вкладка «Как это работает» объясняет цепочку. Тест поиска действительно вызывает embedding-модель и Qdrant и возвращает только публичные работы.

После обновления выполните `php artisan migrate`, обновите/перезапустите Python AI-сервис (добавлен защищённый `/api/v1/diagnostics`) и перезапустите Laravel workers: `php artisan queue:restart`. Для фоновой обработки настройте `QUEUE_CONNECTION=redis` или `database`, `AI_SERVICE_ENABLED=true`, адрес и общий ключ AI API. Запускайте worker с очередью из `AI_PROCESSING_QUEUE` (по умолчанию `ai`), например:

```sh
php artisan queue:work --queue=ai --timeout=600 --tries=3
```

У подключения очереди `retry_after` должен превышать 660 секунд, чтобы не выдавать ещё выполняемое задание повторно; в конфигурации проекта используется 900. При `sync`/`null` повторная индексация из панели недоступна. Старые запуски содержат только итог; промежуточные этапы записывают обновлённые workers. Оборванная попытка может остаться активной: панель отмечает её после 660 секунд, а `php artisan ai:reconcile` восстанавливает зависшие метаданные. Число заданий в очереди не заменяет проверку worker. Проверка сервисов читает список моделей и коллекцию, не запускает генерацию и не меняет векторы.

Данные обновляются каждые 5 секунд, внешняя диагностика — каждые 30 секунд. В скрытой вкладке опрос приостанавливается; есть ручная пауза. При выключенном AI история остаётся доступной. Повторная обработка запускается только вручную для выбранной работы.

## Приватные вложения мессенджера

После обновления выполните `php artisan migrate`: новая миграция добавляет владельца загруженного файла. Новые вложения сохраняются на приватном диске и выдаются только автору загрузки (до отправки) или текущим участникам чата (после отправки). Старые отправленные файлы продолжают открываться через проверку участия; старые неотправленные загрузки без владельца нужно загрузить заново.

В Docker Nginx и Apache `.htaccess` уже добавлена передача старых `/storage/attachments/...` в Laravel. Если используете другой Nginx, включая OpenServer, добавьте в его `server`-блок правило ниже **перед общими regex-правилами статических файлов** и перезагрузите конфигурацию. Иначе ранее загруженные публичные файлы по-прежнему сможет отдавать веб-сервер в обход PHP. Встроенный `php artisan serve` не читает `.htaccess` и предназначен для разработки; для проверки защиты старых файлов используйте настроенный Nginx или Apache.

```nginx
location ~* ^/storage/attachments/ {
    rewrite ^ /index.php last;
}
```

Проверки Vue-поиска: `node --test tests/js/search-suggestions.test.mjs`. Они воспроизводят запоздалые ответы, ошибки сети, смену вкладки и отмену запросов при закрытии компонента.

Русская инструкция: [заполнение базы и импорт артов](docs/ru/data-import.md).
Архитектура AI: [аудит Ollama и семантического поиска](docs/ru/ollama-semantic-search-audit.md).
Векторы: [настройка сервисов и импорт с --index](docs/ru/ai-indexing-setup.md).
Redis: [кэш, очереди, сессии и диагностика](docs/ru/redis-setup.md).

```powershell
php artisan data:setup
php artisan artworks:import --source=met --limit=20 --publish
php artisan artworks:check-imports
```

Импорт использует реальные изображения и метаданные музейных API. Для предварительного просмотра добавьте `--dry-run`; без `--publish` создаются черновики. Обычный `db:seed` заполняет только справочники.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
