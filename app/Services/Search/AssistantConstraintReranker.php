<?php

namespace App\Services\Search;

use App\Services\AiService\AssistantQueryStructure;
use Closure;
use Illuminate\Support\Facades\Log;

class AssistantConstraintReranker
{
    public function __construct(private AssistantConstraintEvidence $evidence) {}

    /** The loader must return current visible works, with Vision metadata and sorted tags. */
    public function rerank(string $query, ?array $plan, array $ids, Closure $loadVisible, int $depth, float $strength, string $baseline): array
    {
        $started = hrtime(true);
        $depth = in_array($depth, [20, 30, 50], true) ? $depth : 20;
        $strength = in_array($strength, [0.5, 1.0], true) ? $strength : 0.5;
        $result = ['ids' => $ids, 'ranks' => [], 'conditions' => [], 'fallback_reason' => null,
            'baseline' => $baseline, 'depth' => $depth, 'strength' => $strength, 'model_calls' => 0];
        try {
            $verified = $plan === null ? null : AssistantQueryStructure::decode(json_encode($plan, JSON_THROW_ON_ERROR), $query);
            if ($verified === null) {
                $result['fallback_reason'] = $plan === null ? 'no_structure' : 'invalid_structure';
            } else {
                $conditions = $this->evidence->conditions($verified);
                $result['conditions'] = array_map(fn ($item) => array_intersect_key($item, array_flip(['key', 'kind', 'text', 'mode', 'weight'])), $conditions);
                if ($conditions === []) {
                    $result['fallback_reason'] = 'no_applicable_conditions';
                } elseif (count($ids) >= 1) {
                    [$result['ids'], $result['ranks']] = $this->reorder($ids, $loadVisible, $depth, $strength, $conditions);
                }
            }
        } catch (\Throwable $error) {
            Log::warning('Assistant constraint evaluation unavailable.', ['exception_type' => $error::class]);
            $result['fallback_reason'] = 'evaluation_unavailable';
        }

        return $result + ['ms' => AssistantSearchDiagnostics::ms($started)];
    }

    private function reorder(array $ids, Closure $loadVisible, int $depth, float $strength, array $conditions): array
    {
        $prefix = array_slice($ids, 0, $depth);
        $visible = $loadVisible(collect($prefix))->keyBy('id');
        $ranks = [];
        $slots = [];
        foreach ($prefix as $index => $id) {
            if (! $visible->has($id)) {
                continue;
            }
            $decisions = $this->evidence->evaluate($visible->get($id), $conditions);
            $adjustment = 0.0;
            foreach ($decisions as $key => $decision) {
                $rule = $conditions[$key];
                $weight = match (true) {
                    str_contains($rule['mode'], 'preferred') => 0.55,
                    $rule['weight'] === 'primary' => 2.2,
                    $rule['weight'] === 'additional' => 1.1,
                    default => 1.5,
                };
                $adjustment += match ($decision['state']) {
                    'confirmed' => $weight,
                    'contradicted' => -1.2 * $weight,
                    default => 0,
                };
            }
            $slots[] = $index;
            $ranks[$id] = ['before' => $index + 1, 'after' => $index + 1,
                'delta' => round(max(-4, min(4, $adjustment)) * $strength, 3), 'evidence' => $decisions];
        }
        $reordered = array_keys($ranks);
        usort($reordered, static fn ($a, $b) => (($ranks[$a]['before'] - $ranks[$a]['delta']) <=> ($ranks[$b]['before'] - $ranks[$b]['delta']))
            ?: ($ranks[$a]['before'] <=> $ranks[$b]['before']));
        foreach ($reordered as $offset => $id) {
            $ids[$slots[$offset]] = $id;
            $ranks[$id]['after'] = $slots[$offset] + 1;
        }

        return [$ids, $ranks];
    }
}
