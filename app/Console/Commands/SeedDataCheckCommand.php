<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SeedDataCheckCommand extends Command
{
    protected $signature = 'seed:check';

    protected $description = 'Совместимый алиас проверки импортированных артов (artworks:check-imports)';

    public function handle(): int
    {
        return $this->call('artworks:check-imports');
    }
}
