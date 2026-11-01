<?php

namespace App\Services\AiService;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Throwable;

class AiDiagnosticsClient
{
    public function check(): array
    {
        $result = ['checked_at' => now()->toISOString(), 'status' => 'disabled', 'ollama' => null, 'qdrant' => null, 'queue' => $this->queue()];
        if (! config('ai.enabled')) {
            return $result;
        }

        try {
            $request = Http::baseUrl(config('ai.service_url'))->acceptJson()->connectTimeout(3)->timeout(12);
            if (config('ai.api_key')) {
                $request->withHeaders(['X-AI-Service-Key' => config('ai.api_key')]);
            }
            $data = $request->get('/api/v1/diagnostics')->throw()->json();
            // Only explicitly selected diagnostics can reach the browser.
            $result['status'] = $data['status'] ?? 'unknown';
            $result['ollama'] = [
                'status' => $data['ollama']['status'] ?? 'unknown',
                'models' => collect($data['ollama']['models'] ?? [])->take(3)->map(fn ($model) => [
                    'role' => $model['role'] ?? '', 'name' => $model['name'] ?? '', 'installed' => (bool) ($model['installed'] ?? false),
                ])->values()->all(),
            ];
            $result['qdrant'] = collect($data['qdrant'] ?? [])->only([
                'status', 'collection', 'points_count', 'indexed_vectors_count', 'dimension', 'distance', 'collection_status',
            ])->all();
        } catch (Throwable) {
            $result['status'] = 'unavailable';
            $result['message'] = 'Диагностика AI API недоступна. Проверьте запуск сервиса, версию API и ключ подключения.';
        }

        return $result;
    }

    private function queue(): array
    {
        $name = config('queue.default');
        $queue = config('ai.queue', 'ai');
        $driver = config("queue.connections.{$name}.driver");
        $result = ['connection' => $name, 'name' => $queue, 'driver' => $driver, 'status' => 'unknown'];
        if (in_array($driver, ['sync', 'null'], true)) {
            return [...$result, 'status' => 'not_async'];
        }
        if (! config('ai.enabled')) {
            return [...$result, 'status' => 'not_checked'];
        }
        try {
            $connection = Queue::connection($name);

            return [...$result, 'status' => 'ok', 'total' => $connection->size($queue), 'pending' => $connection->pendingSize($queue),
                'reserved' => $connection->reservedSize($queue), 'delayed' => $connection->delayedSize($queue)];
        } catch (Throwable) {
            return [...$result, 'status' => 'unavailable'];
        }
    }
}
