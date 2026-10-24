<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ArtworkSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->warn('ArtworkSeeder теперь использует музейный импорт. Рекомендуется artworks:import.');
        $status = $this->command->call('artworks:import', [
            '--source' => 'met',
            '--limit' => 20,
            '--publish' => true,
            '--index' => (bool) config('ai.enabled'),
        ]);
        if ($status !== 0) {
            throw new \RuntimeException('Музейный импорт завершился с ошибками. Подробности приведены выше.');
        }
    }
}
