<?php

namespace App\Services\Search;

use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class AssistantSearchDiagnostics
{
    private const MAX_CANDIDATES = 100;

    private const MAX_BYTES = 65536;

    private array $candidates = [];

    private int $omitted = 0;

    private array $retrievedWorks = [];

    private int $started;

    public array $data;

    private function __construct(string $query)
    {
        $this->started = hrtime(true);
        $flags = ['enabled', 'assistant_enabled', 'assistant_llm_enabled', 'assistant_rag_enabled',
            'assistant_history_enabled', 'assistant_context_enabled', 'assistant_debug_enabled', 'rerank_enabled',
            'colbert_sync.enabled', 'colbert_sync.auto_enabled'];
        $this->data = [
            'schema_version' => 1,
            'captured_at' => now()->toISOString(),
            'query' => ['original' => self::plain($query, 2000), 'used' => null],
            'dialogue' => null,
            'filters' => null,
            'stages' => array_fill_keys(['recent_history', 'resolver_context', 'resolver', 'normalizer', 'lexical', 'dense',
                'rrf', 'hybrid', 'assistant_visibility', 'previous_sources', 'rag_pack', 'composer', 'rag_recheck', 'final'], null),
            'dense_service' => null,
            'rag' => null,
            'performance' => ['total_ms' => null, 'capture_ms' => null],
            'configuration' => [
                'flags' => array_combine($flags, array_map(fn ($flag) => (bool) config('ai.'.$flag), $flags)),
                'models' => ['dense' => null, 'normalizer' => null, 'resolver' => null, 'composer' => null],
                'metadata_schema_version' => config('ai.schema_version'),
                'prompt_version' => config('ai.prompt_version'),
                'search' => array_intersect_key(config('ai.search'), array_flip(['lexical_limit', 'semantic_limit', 'rrf_k'])),
                'assistant_llm_timeout_seconds' => config('ai.assistant_llm_timeout'),
            ],
            'errors' => [],
        ];
    }

    public static function allowed(?User $user): bool
    {
        return $user !== null && config('ai.assistant_debug_enabled') && app()->environment('local');
    }

    public static function requested(Request $request): bool
    {
        return $request->header('X-Assistant-Debug') === '1' && self::allowed($request->user());
    }

    public static function begin(Request $request, string $query): ?self
    {
        if (! self::requested($request)) {
            return null;
        }

        $trace = new self($query);
        app('request')->attributes->set(self::class, $trace);

        return $trace;
    }

    public static function current(): ?self
    {
        return app()->bound('request') ? app('request')->attributes->get(self::class) : null;
    }

    public static function ms(int $started): float
    {
        return round((hrtime(true) - $started) / 1e6, 3);
    }

    public function used(string $query): void
    {
        $this->data['query']['used'] = self::plain($query, 2000);
    }

    public function stage(string $name, int $started, ?int $count = null, array $details = []): void
    {
        $this->data['stages'][$name] = ['ms' => self::ms($started), 'count' => $count, ...$details];
    }

    public function error(string $stage, string $code, ?int $httpStatus = null): void
    {
        $this->data['errors'][] = ['stage' => $stage, 'code' => $code, 'http_status' => $httpStatus];
    }

    public function model(string $stage, ?string $name): void
    {
        if ($name && preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9_./:-]{0,95}\z~', $name)) {
            $this->data['configuration']['models'][$stage] = $name;
        }
    }

    public function observe(string $stage, Response $response): void
    {
        if ($response->ok()) {
            $this->model($stage, $response->header('X-Assistant-Model'));
        } else {
            $this->error($stage, $response->status() === 504 ? 'service_timeout' : 'service_rejected', $response->status());
        }
    }

    public function candidate(int $id, array $fields): void
    {
        if (! isset($this->candidates[$id]) && count($this->candidates) >= self::MAX_CANDIDATES) {
            $this->omitted++;

            return;
        }

        $this->candidates[$id] = $fields + ($this->candidates[$id] ?? [
            'artwork_id' => $id, 'dense_score' => null, 'dense_rank' => null, 'lexical_rank' => null,
            'rrf_score' => null, 'rrf_rank' => null, 'hybrid_rank' => null, 'final_rank' => null,
            'retrieved' => false, 'final' => false, 'exclusions' => [],
        ]);
    }

    public function exclude(int $id, string $reason): void
    {
        $this->candidate($id, ['exclusions' => [...($this->candidates[$id]['exclusions'] ?? []), $reason]]);
    }

    public function facts(array $facts, Collection $references): void
    {
        $this->data['rag'] = ['facts' => array_values(array_filter(array_map(
            fn ($fact) => $references->has($fact['source_ref']) ? [
                ...$fact, 'artwork_id' => $references->get($fact['source_ref']),
                'value' => $fact['fact_ref'] === 'f7' ? '[атрибуция скрыта]' : self::plain($fact['value'], 240),
            ] : null, $facts,
        ))), 'composer_action' => null, 'selected' => [], 'transport_attempted' => null];
    }

    public function retainMetadata(Collection $works): void
    {
        foreach ($works as $work) {
            if ($work->relationLoaded('aiMetadata')) {
                $this->retrievedWorks[$work->id] = $work;
            }
        }
    }

    public function plan(?array $plan, Collection $references): void
    {
        $this->data['rag']['composer_action'] = $plan['action'] ?? null;
        $this->data['rag']['selected'] = array_values(array_filter(array_map(
            fn ($selection) => $references->has($selection['source_ref'])
                ? [...$selection, 'artwork_id' => $references->get($selection['source_ref'])] : null,
            $plan['selections'] ?? [],
        )));
    }

    public function finish(User $user, string $status, array $sources, AssistantArtworkSearch $search): array
    {
        $capture = hrtime(true);
        $this->stage('final', $capture, count($sources), ['status' => $status]);
        foreach ($sources as $index => $source) {
            $this->candidate($source['artwork_id'], ['final' => true, 'final_rank' => $index + 1]);
        }
        $visible = $this->candidates === [] ? collect() : $search->visible(collect(array_keys($this->candidates)), $user)->keyBy('id');
        $trace = self::restrict([
            ...$this->data,
            'candidates' => array_values($this->candidates),
            'omitted_candidate_entries' => $this->omitted,
        ], $visible->pluck('id')->all());

        // Keep stage ranks for all permitted candidates and richer data for the works highest in the actual flow.
        $priority = $visible->sortBy(fn (Artwork $work) => match (true) {
            $this->candidates[$work->id]['final'] => 0,
            $this->candidates[$work->id]['retrieved'] => 1,
            default => 2,
        })->take(20)->map(fn (Artwork $work) => $this->retrievedWorks[$work->id] ?? $work);
        (new EloquentCollection($priority->all()))->loadMissing('aiMetadata');
        $runs = AiProcessingRun::query()->useWritePdo()->whereIn('id', AiProcessingRun::query()
            ->selectRaw('MAX(id)')->whereIn('artwork_id', $priority->pluck('id'))
            ->where('operation', 'analyze_and_index')->where('status', 'success')->groupBy('artwork_id'))
            ->get(['artwork_id', 'parameters', 'finished_at'])->keyBy('artwork_id');
        $metadata = $priority->mapWithKeys(fn (Artwork $work) => [$work->id => $this->metadata($work, $runs->get($work->id))]);
        $trace['omitted_metadata'] = 0;
        foreach ($trace['candidates'] as &$candidate) {
            $work = $visible->get($candidate['artwork_id']);
            $candidate['source'] = [...AssistantArtworkSearch::card($work), 'title' => self::plain($work->title, 220)];
            $candidate['metadata'] = null;
        }
        unset($candidate);

        $candidatePositions = array_flip(array_column($trace['candidates'], 'artwork_id'));
        $bytes = strlen(json_encode($trace, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        foreach ($metadata as $id => $value) {
            $cost = strlen(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($bytes + $cost > self::MAX_BYTES - 1024) {
                $trace['omitted_metadata']++;
                continue;
            }
            $trace['candidates'][$candidatePositions[$id]]['metadata'] = $value;
            $bytes += $cost;
        }
        $trace['performance'] = ['total_ms' => self::ms($this->started), 'capture_ms' => self::ms($capture)];

        return $trace;
    }

    public static function restrict(array $trace, array $visibleIds): array
    {
        $visible = array_fill_keys($visibleIds, true);
        $filter = fn (array $rows) => array_values(array_filter($rows, fn ($row) => isset($visible[$row['artwork_id']])));
        $trace['candidates'] = $filter($trace['candidates']);
        if ($trace['rag'] !== null) {
            $trace['rag']['facts'] = $filter($trace['rag']['facts']);
            $trace['rag']['selected'] = $filter($trace['rag']['selected']);
        }

        return $trace;
    }

    private function metadata(Artwork $work, ?AiProcessingRun $run): ?array
    {
        $meta = $work->aiMetadata;
        if ($meta === null) {
            return null;
        }

        $source = $run?->parameters['analysis_source'] ?? null;
        // Historical successful runs did not always retain the content hash.
        $matchesTime = $run?->finished_at && $meta->indexed_at
            && abs($run->finished_at->getTimestamp() - $meta->indexed_at->getTimestamp()) <= 2;
        $matchesHash = ($run?->parameters['content_hash'] ?? $meta->content_hash) === $meta->content_hash;
        $result = ['summary' => self::plain($meta->summary, 480),
            'analysis_source' => $matchesTime && $matchesHash && in_array($source, ['vision', 'text_fallback'], true) ? $source : null,
            'status' => $meta->status,
            'vision_model' => self::plain($meta->vision_model, 96),
            'chat_model' => self::plain($meta->chat_model, 96),
            'embedding_model' => self::plain($meta->embedding_model, 96),
            'metadata_schema_version' => $meta->metadata_schema_version,
            'prompt_version' => $meta->prompt_version,
            'embedding_dimension' => $meta->embedding_dimension,
        ];
        foreach (['objects', 'themes', 'styles', 'colors', 'mood', 'tags_ru', 'tags_en'] as $field) {
            $result[$field] = $meta->$field === null ? null : array_map(
                fn ($value) => self::plain($value, 64), array_slice(array_values(array_filter($meta->$field, 'is_string')), 0, 8),
            );
        }

        return $result;
    }

    public static function publicText(?string $value, int $bytes): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('~(?:https?://|www\.)\S+|[\w.+-]+@[\w.-]+\.[a-z]{2,}~iu', '[скрыто]', self::redactSecrets($value)) ?? '';

        return mb_strcut($value, 0, $bytes, 'UTF-8');
    }

    public static function redactSecrets(string $value): string
    {
        return preg_replace('~(?:sk|ghp|github_pat)[_-][a-z0-9_-]{12,}|\bBearer\s+[a-z0-9._=-]+|\b[\w-]*(?:password|api[_-]?key|secret|access[_-]?token|app[_-]?key)\s*[:=]\s*(?:"[^"]*"|\x27[^\x27]*\x27|\S+)~iu',
            '[скрыто]', $value) ?? '';
    }

    private static function plain(?string $value, int $bytes): ?string
    {
        return self::publicText($value, $bytes);
    }
}
