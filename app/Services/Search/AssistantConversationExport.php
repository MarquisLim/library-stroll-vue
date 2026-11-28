<?php

namespace App\Services\Search;

use App\Models\AssistantConversation;
use App\Models\AssistantTurn;
use App\Models\User;
use Illuminate\Support\Collection;

final class AssistantConversationExport
{
    public function __construct(private AssistantArtworkSearch $search, private AssistantSavedSources $sources) {}

    public function write(AssistantConversation $conversation, User $user, array $header, bool $extended): void
    {
        $sections = $extended ? ['sources', 'metadata', 'diagnostics', 'rag', 'performance', 'configuration', 'errors'] : ['sources'];
        $files = [];
        foreach ($sections as $section) {
            $files[$section] = tmpfile();
            if ($files[$section] === false) {
                throw new \RuntimeException('Could not allocate dialogue export stream.');
            }
        }

        $turns = $conversation->turns()->useWritePdo();
        $lastSequence = (int) (clone $turns)->max('sequence');
        echo substr($this->json($header), 0, -2).",\n    \"messages\": [";
        $first = true;
        try {
            foreach ($turns->where('sequence', '<=', $lastSequence)->lazyById(20, 'sequence') as $turn) {
                $row = $this->turn($turn, $user, $extended);
                $assistant = ['role' => 'assistant', 'turn_id' => $turn->id, 'sequence' => $turn->sequence,
                    'created_at' => $turn->created_at->toISOString(), 'status' => $turn->result_status,
                    'answer' => $row['answer'], 'source_status' => $row['source_status'], ...$turn->replyPayload()];
                $userMessage = ['role' => 'user', 'turn_id' => $turn->id, 'sequence' => $turn->sequence,
                    'created_at' => $turn->created_at->toISOString(), 'query' => AssistantSearchDiagnostics::redactSecrets($turn->user_query),
                    'requested_limit' => $turn->requested_limit];
                echo ($first ? "\n" : ",\n").$this->indent($userMessage, 8).",\n".$this->indent($assistant, 8);

                foreach ($sections as $section) {
                    fwrite($files[$section], ($first ? "\n" : ",\n").'        '.$this->json($turn->id).': '.$this->indent($row[$section], 8, false));
                }
                $first = false;
            }

            echo "\n    ]";
            foreach ($sections as $section) {
                echo ",\n    ".$this->json($section).': {';
                rewind($files[$section]);
                fpassthru($files[$section]);
                echo "\n    }";
            }
            echo "\n}\n";
        } finally {
            foreach ($files as $file) {
                fclose($file);
            }
        }
    }

    public function historyDiagnostics(Collection $turns, User $user): array
    {
        $traces = $turns->mapWithKeys(fn (AssistantTurn $turn) => [$turn->id => $turn->diagnostics])->filter();
        if ($traces->isEmpty()) {
            return [];
        }
        $ids = $traces->flatMap(fn ($trace) => array_column($trace['candidates'], 'artwork_id'))->unique()->values();
        $visibleIds = $this->search->visible($ids, $user)->pluck('id')->all();

        return $traces->map(fn ($trace) => AssistantSearchDiagnostics::restrict($trace, $visibleIds))->all();
    }

    private function turn(AssistantTurn $turn, User $user, bool $extended): array
    {
        $trace = $extended ? $turn->diagnostics : null;
        $citations = $turn->citations();
        $fallback = $turn->fallback_source_ids ?? [];
        $ids = collect([...array_column($citations, 'artwork_id'), ...$fallback, ...array_column($trace['candidates'] ?? [], 'artwork_id')])
            ->unique()->values();
        $visible = $ids->isEmpty() ? collect() : $this->search->visible($ids, $user, withMetadata: $citations !== [])->keyBy('id');
        $restored = $this->sources->restore($citations, $this->sources->resolve($citations, $user, $visible));
        if ($fallback !== []) {
            $restored['sources'] = $visible->only($fallback)->values()->map(
                fn ($work, $index) => ['source_ref' => 's'.($index + 1), ...AssistantArtworkSearch::card($work)],
            )->all();
            $restored['source_status'] = match (count($restored['sources'])) {
                0 => 'unavailable',
                count($fallback) => 'available',
                default => 'partial',
            };
        }
        foreach ($restored['sources'] as &$source) {
            $source['url'] = route('artworks.show', ['artwork' => $source['artwork_id']]);
            $source['title'] = AssistantSearchDiagnostics::publicText($source['title'], 500);
        }
        unset($source);
        foreach ($restored['answer'] as &$fact) {
            $fact['text'] = $fact['fact_ref'] === 'f7' ? '[атрибуция скрыта]' : AssistantSearchDiagnostics::publicText($fact['text'], 500);
        }
        unset($fact);

        $row = $restored + array_fill_keys(['metadata', 'diagnostics', 'rag', 'performance', 'configuration', 'errors'], null);
        if ($trace === null) {
            return $row;
        }
        $trace = AssistantSearchDiagnostics::restrict($trace, $visible->keys()->all());
        $finalRanks = collect($trace['candidates'])->where('final', true)->pluck('final_rank', 'artwork_id');
        $row['sources'] = collect($row['sources'])->sortBy(
            fn ($source) => $finalRanks->get($source['artwork_id']) ?? PHP_INT_MAX,
        )->values()->all();
        foreach (['rag', 'performance', 'configuration', 'errors'] as $section) {
            $row[$section] = $trace[$section];
            unset($trace[$section]);
        }
        $row['errors'] = ['events' => $row['errors'], 'candidate_exclusions' => []];
        $row['metadata'] = [];
        foreach ($trace['candidates'] as &$candidate) {
            $row['metadata'][] = ['artwork_id' => $candidate['artwork_id'], 'metadata' => $candidate['metadata']];
            if ($candidate['exclusions'] !== []) {
                $row['errors']['candidate_exclusions'][] = [
                    'artwork_id' => $candidate['artwork_id'], 'reasons' => $candidate['exclusions'],
                ];
            }
            $candidate['source']['url'] = route('artworks.show', ['artwork' => $candidate['artwork_id']]);
            unset($candidate['metadata'], $candidate['exclusions']);
        }
        unset($candidate);
        $row['diagnostics'] = $trace;

        return $row;
    }

    private function json(mixed $value): string
    {
        return json_encode($this->redact($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->redact(...), $value);
        }
        if (! is_string($value)) {
            return $value;
        }
        return AssistantSearchDiagnostics::redactSecrets($value);
    }

    private function indent(mixed $value, int $spaces, bool $first = true): string
    {
        $prefix = str_repeat(' ', $spaces);

        return ($first ? $prefix : '').str_replace("\n", "\n".$prefix, $this->json($value));
    }
}
