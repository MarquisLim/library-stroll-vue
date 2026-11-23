<?php

namespace App\Services\Search;

use App\Models\Artwork;
use App\Models\User;
use App\Services\AiService\AssistantRagContext;
use Illuminate\Support\Collection;

class AssistantSavedSources
{
    public function __construct(private AssistantArtworkSearch $search, private AssistantRagContext $context) {}

    public static function reference(Artwork $artwork, array $snapshot, string $fact): array
    {
        return [
            'artwork_id' => $artwork->id,
            'fact_ref' => $fact,
            'revision_hash' => $snapshot['revision'],
            'fact_sha256' => hash('sha256', $snapshot['facts'][$fact]['value']),
        ];
    }

    /**
     * Validate a page or a set of recent turns in one batch. The keys retain the full saved identity.
     *
     * @return array<string, array{reference: array, artwork: Artwork, fact: array}>
     */
    public function resolve(array $references, User $user, ?Collection $visible = null): array
    {
        $references = array_filter($references, fn ($ref) => $this->key($ref) !== null);
        if ($references === []) {
            return [];
        }

        $visible ??= $this->search->visible(collect(array_column($references, 'artwork_id'))->unique(), $user, withMetadata: true);
        $visible = $visible->keyBy('id');
        $snapshots = [];
        $resolved = [];
        foreach ($references as $ref) {
            $id = $ref['artwork_id'];
            $artwork = $visible->get($id);
            if ($artwork === null) {
                continue;
            }
            $snapshot = $snapshots[$id] ??= $this->context->snapshot($artwork);
            $fact = $snapshot['facts'][$ref['fact_ref']] ?? null;
            if ($fact !== null && hash_equals($snapshot['revision'], $ref['revision_hash'])
                && hash_equals(hash('sha256', $fact['value']), $ref['fact_sha256'])) {
                $resolved[$this->key($ref)] = ['reference' => self::reference($artwork, $snapshot, $ref['fact_ref']), 'artwork' => $artwork, 'fact' => $fact];
            }
        }

        return $resolved;
    }

    public function restore(array $references, array $resolved): array
    {
        $answer = [];
        $sources = [];
        foreach ($references as $ref) {
            $entry = $resolved[$this->key($ref) ?? ''] ?? null;
            if ($entry === null) {
                continue;
            }
            $id = $ref['artwork_id'];
            $sources[$id] ??= ['source_ref' => 's'.(count($sources) + 1), ...AssistantArtworkSearch::card($entry['artwork'])];
            $answer[] = [
                'source_ref' => $sources[$id]['source_ref'],
                'fact_ref' => $ref['fact_ref'],
                'text' => $this->context->render($ref['fact_ref'], $entry['fact']['value']),
            ];
        }

        return [
            'answer' => $answer,
            'sources' => array_values($sources),
            'source_status' => match (true) {
                $references === [] => 'none',
                $answer === [] => 'unavailable',
                count($answer) < count($references) => 'partial',
                default => 'available',
            },
        ];
    }

    /**
     * Run under the short append transaction, after locking the conversation.
     */
    public function confirm(array $result, User $owner): void
    {
        $ids = collect(array_column($result['sources'], 'artwork_id'));
        if ($ids->isEmpty()) {
            return;
        }

        $locked = Artwork::withoutGlobalScopes()->whereKey($ids->all())->orderBy('id')->lockForUpdate()->get(['id', 'user_id']);
        User::withoutGlobalScopes()->whereKey($locked->pluck('user_id')->unique()->all())
            ->orderBy('id')->lockForUpdate()->get(['id']);
        $visible = $this->search->visible($ids, $owner, withMetadata: true)->keyBy('id');

        foreach ($result['sources'] as $source) {
            $artwork = $visible->get($source['artwork_id']);
            abort_if($artwork === null || $source['title'] !== $artwork->title, 409, 'Sources changed during processing.');
        }
        $references = $result['references'];
        $scope = $result['scope'];
        $resolved = $this->resolve([...$references, ...$scope], $owner, $visible);
        foreach ($references as $ref) {
            abort_unless(isset($resolved[$this->key($ref)]), 409, 'Sources changed during processing.');
        }

        // Restricted card-only results must still have a verified reference from that same scope.
        $scopedIds = [];
        foreach ($scope as $ref) {
            if (isset($resolved[$this->key($ref)])) {
                $scopedIds[$ref['artwork_id']] = true;
            }
        }
        foreach (array_column($scope, 'artwork_id') as $id) {
            abort_unless(isset($scopedIds[$id]), 409, 'Sources changed during processing.');
        }
    }

    private function key(mixed $ref): ?string
    {
        if (! is_array($ref) || ! is_int($ref['artwork_id'] ?? null) || $ref['artwork_id'] < 1
            || ! is_string($ref['fact_ref'] ?? null) || ! preg_match('/\Af[1-7]\z/', $ref['fact_ref'])
            || ! is_string($ref['revision_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $ref['revision_hash'])
            || ! is_string($ref['fact_sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $ref['fact_sha256'])) {
            return null;
        }

        return implode(':', [$ref['artwork_id'], $ref['fact_ref'], $ref['revision_hash'], $ref['fact_sha256']]);
    }
}
