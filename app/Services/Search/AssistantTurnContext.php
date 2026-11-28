<?php

namespace App\Services\Search;

use App\Models\AssistantTurn;
use App\Models\User;
use App\Services\AiService\AssistantRagContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssistantTurnContext
{
    public function __construct(private AssistantSavedSources $saved) {}

    public function prepare(string $query, Collection $recent, User $owner): array
    {
        $references = $recent->flatMap(fn (AssistantTurn $turn) => $turn->citations())->all();
        $entries = $this->saved->resolve($references, $owner);
        $payload = ['query' => $query, 'previous_queries' => [], 'facts' => []];
        $ids = [];
        $allow = [];
        foreach ($entries as $entry) {
            $reference = $entry['reference'];
            $id = $reference['artwork_id'];
            if (! isset($ids[$id]) && count($ids) === 5) {
                continue;
            }

            $source = $ids[$id] ?? 's'.(count($ids) + 1);
            $candidate = [...$payload['facts'], [
                'source_ref' => $source, 'fact_ref' => $reference['fact_ref'], ...$entry['fact'],
            ]];
            if ($this->fits([...$payload, 'facts' => $candidate])) {
                $payload['facts'] = $candidate;
                $ids[$id] = $source;
                $allow[$source][] = $reference;
            }
        }

        foreach ($recent->take(4) as $turn) {
            $previous = [...$payload['previous_queries'], $turn->user_query];
            if ($this->fits([...$payload, 'previous_queries' => $previous])) {
                $payload['previous_queries'] = $previous;
            }
        }

        return [$payload, $allow];
    }

    public function dependsOnHistory(string $query): bool
    {
        $pattern = '~\b(?:из (?:них|этих|предыдущих)|среди (?:них|этих)|предыдущ\pL*'
            .'|(?:эти|это|эту|эта|этот|этой|этого|этих) (?:работ|изображен|картин|рисунк|фото)\pL*'
            .'|(?:of|among) (?:them|these|those)|(?:this|that|these|those|the previous) (?:image|work|picture)s?'
            .'|which ones|like (?:it|that)|them)\b~iu';

        return (bool) preg_match($pattern, Str::squish($query));
    }

    public function independent(string $query): bool
    {
        return ! $this->dependsOnHistory($query)
            && ! preg_match('/\b(?:похож\pL*|ещ[её]|други\pL*|similar|another|more)\b/iu', $query)
            && (bool) preg_match('/\A(?:пожалуйста,?\s+|please\s+)?(?:найди(?:те)?|покажи(?:те)?|подбери(?:те)?|ищу|find|show|search for|look for)\s+\S/iu', $query);
    }

    private function fits(array $payload): bool
    {
        return strlen(AssistantRagContext::inputJson($payload)) <= AssistantRagContext::INPUT_BYTES;
    }
}
