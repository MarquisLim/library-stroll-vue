<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Models\User;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\ArtworkImport\ArtworkImporter;
use App\Services\ArtworkImport\MuseumSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportArtworksCommand extends Command
{
    protected $signature = 'artworks:import
        {--source=met : Источник: met или artic}
        {--query= : Поисковый запрос к музею (обычно на английском)}
        {--limit=20 : Максимум новых работ, от 1 до 200}
        {--page=1 : Начальная страница источника (40 кандидатов на страницу)}
        {--max-pages=3 : Максимум просматриваемых страниц, от 1 до 10}
        {--user= : ID существующего владельца; иначе отдельный аккаунт импорта}
        {--collection= : ID коллекции выбранного владельца}
        {--publish : Сразу публиковать; иначе создавать черновики}
        {--index : После публикации отправить работы на векторную индексацию в очередь ai}
        {--dry-run : Показать метаданные без скачивания и записи}';

    protected $description = 'Импортировать реальные произведения и их теги из открытых музейных API';

    public function handle(MuseumSource $museum, ArtworkImporter $importer): int
    {
        $source = (string) $this->option('source');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $page = filter_var($this->option('page'), FILTER_VALIDATE_INT);
        $maxPages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
        if (! in_array($source, MuseumSource::SOURCES, true) || ! $limit || $limit < 1 || $limit > 200
            || ! $page || $page < 1 || $page > 1000 || ! $maxPages || $maxPages < 1 || $maxPages > 10) {
            $this->error('Проверьте source=artic|met, limit=1..200, page=1..1000, max-pages=1..10.');

            return self::INVALID;
        }
        if (! Schema::hasTable('artwork_imports')) {
            $this->error('Сначала выполните php artisan migrate.');

            return self::FAILURE;
        }
        if ($this->option('index') && ! $this->option('publish')) {
            $this->error('--index требует --publish.');

            return self::INVALID;
        }
        if ($this->option('index') && ! $this->option('dry-run') && ! config('ai.enabled')) {
            $this->error('Для --index установите AI_SERVICE_ENABLED=true и запустите AI API и worker ai.');

            return self::FAILURE;
        }

        $owner = $this->option('user') !== null ? User::find($this->option('user')) : null;
        $collection = $this->option('collection') !== null ? Collection::find($this->option('collection')) : null;
        if (($this->option('user') !== null && $owner === null)
            || ($this->option('collection') !== null && ($collection === null || $owner === null || $collection->user_id !== $owner->id))) {
            $this->error('Укажите существующего --user и принадлежащую ему --collection.');

            return self::INVALID;
        }

        $created = $skipped = $errors = $previewed = 0;
        $consecutiveErrors = 0;
        $queued = 0;
        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'Предпросмотр: база и файлы не изменяются.' : 'Импорт '.$source.': '.($this->option('publish') ? 'публикация' : 'черновики'));

        try {
            foreach ($museum->candidates($source, trim((string) $this->option('query')), $page, $maxPages) as $candidate) {
                if ($importer->exists($candidate)) {
                    $skipped++;

                    continue;
                }
                if ($candidate->tags === []) {
                    $this->warn("Пропуск {$candidate->externalId}: источник не предоставил теги.");
                    $skipped++;

                    continue;
                }
                if ($dryRun) {
                    $this->line("[{$candidate->externalId}] {$candidate->title}");
                    $this->line('  Теги: '.implode(', ', $candidate->tags));
                    $this->line('  Источник: '.$candidate->sourceUrl);
                    $previewed++;
                } else {
                    try {
                        $artwork = $importer->import($candidate, $owner, $collection, (bool) $this->option('publish'));
                        $created++;
                        if ($this->option('index') && app(ArtworkIndexingService::class)->dispatch($artwork)) {
                            $queued++;
                        }
                        $consecutiveErrors = 0;
                        $this->line("Создан #{$artwork->id}: {$artwork->title}");
                    } catch (Throwable $exception) {
                        $errors++;
                        $this->warn("Не удалось импортировать {$candidate->externalId}: ".$exception->getMessage());
                        if (++$consecutiveErrors >= 3) {
                            $this->error('Три ошибки подряд: импорт остановлен. Проверьте доступ к изображениям или выберите другой источник.');
                            break;
                        }
                    }
                }
                if (($dryRun ? $previewed : $created) >= $limit) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            $errors++;
            $this->error('Ошибка API источника: '.$exception->getMessage());
        }

        $this->table(['Создано', 'Пропущено', 'Ошибок', 'Предпросмотр'], [[$created, $skipped, $errors, $previewed]]);
        if (($dryRun ? $previewed : $created) < $limit) {
            $this->warn('Лимит новых работ не достигнут: измените запрос, --page или --max-pages.');
        }
        if ($created > 0) {
            $this->info('Проверка: php artisan artworks:check-imports');
            $this->line($this->option('index')
                ? "В очередь AI отправлено: {$queued}. Векторы появятся после успешного выполнения worker."
                : 'Для векторов добавьте --publish --index или используйте ai:reindex-all.');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
