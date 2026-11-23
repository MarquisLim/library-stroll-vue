<?php

namespace App\Services\Search;

use App\Models\Artwork;
use App\Models\User;
use App\Services\AiService\AssistantAnswerComposer;
use App\Services\AiService\AssistantRagContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AssistantRagAnswer
{
    public function __construct(
        private AssistantArtworkSearch $search,
        private AssistantRagContext $context,
        private AssistantAnswerComposer $composer,
        private AssistantSavedSources $saved,
        private AssistantPreviousDates $dates,
    ) {}

    public function search(string $query, int $limit, User $user): array
    {
        return $this->compose($query, $limit, $user, $this->search->retrieve($query, $user, withMetadata: true));
    }

    public function previous(string $query, int $limit, User $user, array $scope): array
    {
        $entries = $this->saved->resolve($scope, $user);
        if ($entries === []) {
            return ['status' => 'clarification_required', 'answer' => [], 'sources' => [], 'references' => [], 'scope' => []];
        }

        $candidates = collect(array_column($entries, 'artwork'))->unique('id')->values();
        $evidence = collect($this->dates->evidence($query, $entries))->groupBy('reference.artwork_id');
        if ($evidence->isNotEmpty()) {
            // When the question has a supported period constraint, only matches enter composition.
            $candidates = $candidates->filter(fn (Artwork $work) => $evidence->has($work->id))->values();
        }

        return $this->compose($query, $limit, $user, $candidates, array_column($entries, 'reference'), $evidence);
    }

    public static function payload(array $result): array
    {
        return ['status' => $result['status'], 'answer' => $result['answer'], 'sources' => $result['sources']];
    }

    private function compose(string $query, int $limit, User $user, Collection $candidates, ?array $scope = null, ?Collection $evidence = null): array
    {
        $initial = $candidates->mapWithKeys(fn (Artwork $artwork, int $index) => ['s'.($index + 1) => $artwork]);
        if ($evidence === null) {
            [$facts, $snapshots] = $this->context->prepare($query, $candidates->take($limit));
        } else {
            $snapshots = [];
            foreach ($initial->take($limit) as $source => $artwork) {
                $entries = $evidence->get($artwork->id, collect());
                $snapshots[$source] = [
                    'revision' => $entries->first()['reference']['revision_hash'] ?? null,
                    'facts' => $entries->mapWithKeys(fn ($entry) => [$entry['reference']['fact_ref'] => $entry['fact']])->all(),
                ];
            }
            $facts = $this->context->packFacts($query, $snapshots);
        }
        if ($facts === []) {
            return $this->result('insufficient_evidence', [], $initial->take($limit), [], $scope ?? []);
        }

        $started = hrtime(true);
        $plan = $this->composer->compose($query, $facts);
        Log::debug('Assistant composition completed.', ['elapsed_ms' => (hrtime(true) - $started) / 1e6, 'valid_plan' => $plan !== null]);
        $current = $this->search->visible($candidates->pluck('id'), $user, withMetadata: true)->keyBy('id');
        if ($scope !== null) {
            $scope = array_column($this->saved->resolve($scope, $user, $current), 'reference');
            $current = $current->only(array_column($scope, 'artwork_id'));
        }
        $sources = $initial->map(fn (Artwork $artwork) => $current->get($artwork->id))->filter();
        if (($plan['action'] ?? null) !== 'compose') {
            return $this->result($plan['action'] ?? 'search_fallback', [], $sources->take($limit), [], $scope ?? []);
        }

        $answer = [];
        $references = [];
        $checked = [];
        foreach ($plan['selections'] as ['source_ref' => $source, 'fact_ref' => $ref]) {
            $artwork = $sources->get($source);
            if ($artwork === null) {
                return $this->result('search_fallback', [], $sources->take($limit), [], $scope ?? []);
            }
            $actual = $checked[$source] ??= $this->context->snapshot($artwork);
            $old = $snapshots[$source];
            $matches = $evidence === null
                ? $actual === $old
                : ($actual['revision'] === $old['revision'] && ($actual['facts'][$ref] ?? null) === $old['facts'][$ref]);
            if (! $matches) {
                return $this->result('search_fallback', [], $sources->take($limit), [], $scope ?? []);
            }

            $answer[] = [
                'source_ref' => $source,
                'fact_ref' => $ref,
                'text' => $this->context->render($ref, $actual['facts'][$ref]['value']),
            ];
            $references[] = AssistantSavedSources::reference($artwork, $actual, $ref);
        }

        return $this->result('ok', $answer, $sources->only(array_column($answer, 'source_ref')), $references, $scope ?? []);
    }

    private function result(string $status, array $answer, Collection $sources, array $references, array $scope): array
    {
        $ids = $sources->pluck('id')->all();

        return [
            'status' => $sources->isEmpty() ? 'no_results' : $status,
            'answer' => $answer,
            'sources' => $sources->map(fn (Artwork $artwork, string $ref) => [
                'source_ref' => $ref,
                ...AssistantArtworkSearch::card($artwork),
            ])->values()->all(),
            'references' => $references,
            'scope' => array_values(array_filter($scope, fn ($ref) => in_array($ref['artwork_id'], $ids, true))),
        ];
    }
}
