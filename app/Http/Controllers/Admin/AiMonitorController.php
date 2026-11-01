<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\AiServiceClient;
use App\Http\Controllers\Controller;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Services\AiService\AiDiagnosticsClient;
use App\Services\AiService\AiMonitoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

class AiMonitorController extends Controller
{
    public function index(Request $request, AiMonitoringService $monitor)
    {
        return Inertia::render('Admin/AiMonitor', ['initial' => $monitor->snapshot($this->filters($request))]);
    }

    public function data(Request $request, AiMonitoringService $monitor)
    {
        return response()->json($monitor->snapshot($this->filters($request)));
    }

    public function diagnostics(AiDiagnosticsClient $client)
    {
        return response()->json($client->check());
    }

    public function search(Request $request, AiServiceClient $client)
    {
        $data = $request->validate(['query' => 'required|string|max:300']);
        abort_unless(config('ai.enabled'), 422, 'AI-сервис выключен.');
        try {
            $result = $client->search($data['query'], 10, ['is_published' => true, 'is_private' => false, 'is_blocked' => false]);
            $artworks = Artwork::publiclyVisible()->whereIn('id', $result->artworkIds())->get(['id', 'title'])->keyBy('id');
            $results = collect($result->results)->take(10)->filter(fn ($row) => isset($artworks[$row['artwork_id']]))
                ->map(fn ($row) => ['id' => $row['artwork_id'], 'title' => $artworks[$row['artwork_id']]->title, 'score' => $row['score'], 'rank' => $row['rank']])->values();

            return response()->json(['results' => $results, 'model' => $result->model, 'latency_ms' => $result->latencyMs, 'checked_at' => now()->toISOString()]);
        } catch (Throwable) {
            return response()->json(['message' => 'Семантический поиск недоступен. Проверьте embedding-модель и Qdrant.'], 503);
        }
    }

    private function filters(Request $request): array
    {
        return $request->validate(['status' => ['nullable', Rule::in([...ArtworkAiMetadata::STATUSES, 'missing'])],
            'search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:1000000']);
    }
}
