<?php

namespace App\Console\Commands;

use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use Illuminate\Console\Command;

class AiStatusCommand extends Command
{
    protected $signature = 'ai:status {--errors=5 : Показать до N последних ошибок}';

    protected $description = 'Показать состояние векторной индексации в Laravel';

    public function handle(): int
    {
        $this->line('AI: '.(config('ai.enabled') ? 'включён' : 'выключен'));
        $this->line('Очередь: '.config('ai.queue'));
        $this->line('Опубликованных работ: '.Artwork::where('is_published', true)->count());
        $counts = ArtworkAiMetadata::query()->select('status')->selectRaw('COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status');
        $this->table(['Статус', 'Количество'], collect(ArtworkAiMetadata::STATUSES)
            ->map(fn ($status) => [$status, $counts[$status] ?? 0])->all());
        $limit = max(0, min(50, (int) $this->option('errors')));
        if ($limit > 0) {
            foreach (ArtworkAiMetadata::where('status', 'failed')->latest('updated_at')->limit($limit)->get() as $metadata) {
                $this->warn('#'.$metadata->artwork_id.': '.$metadata->last_error);
            }
        }
        $this->line('Это состояние MySQL. Доступность моделей и Qdrant: php artisan ai:health');

        return self::SUCCESS;
    }
}
