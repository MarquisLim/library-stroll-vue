<?php

namespace App\Console\Commands;

use App\Contracts\AiServiceClient;
use Illuminate\Console\Command;

class AiHealthCommand extends Command
{
    protected $signature = 'ai:health';
    protected $description = 'Check AI service health (Ollama + Qdrant)';

    public function handle(AiServiceClient $client): int
    {
        if (! config('ai.enabled')) {
            $this->warn('AI service is disabled (AI_SERVICE_ENABLED=false).');
            return self::SUCCESS;
        }

        $this->info('Checking AI service at ' . config('ai.service_url') . '...');

        $result = $client->health();

        $this->table(
            ['Service', 'Status'],
            [
                ['AI Service', $result->status],
                ['Ollama', $result->ollama],
                ['Qdrant', $result->qdrant],
            ],
        );

        if ($result->isOk()) {
            $this->info('All services are healthy.');
            return self::SUCCESS;
        }

        $this->error('Some services are degraded or unavailable.');
        return self::FAILURE;
    }
}
