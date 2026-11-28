<?php

namespace App\Services\Search;

use App\Services\AiService\ColbertCatalogDocument;
use App\Services\AiService\HttpAiServiceClient;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

class AssistantCandidateReranker
{
    public function __construct(private HttpAiServiceClient $client, private ColbertCatalogDocument $documents) {}

    /** The loader must return only works currently visible to the requesting user, with metadata and sorted tags. */
    public function rerank(string $query, array $ids, Closure $loadVisible, int $depth, string $strategy): array
    {
        $started = hrtime(true);
        $ids = array_values(array_unique($ids));
        $depth = in_array($depth, [20, 30, 50], true) ? $depth : 30;
        $prefix = array_slice($ids, 0, $depth);
        $ranks = [];
        $keys = [];
        $reason = null;
        $transport = false;
        $serviceMs = null;
        $model = null;
        $ordered = $ids;
        try {
            $visible = $loadVisible(collect($prefix))->keyBy('id');
            foreach ($prefix as $offset => $id) {
                if (! $visible->has($id)) {
                    continue;
                }
                $doc = $this->documents->make($visible->get($id));
                $ranks[$id] = ['before' => $offset + 1, 'after' => $offset + 1, 'score' => null,
                    'document' => $doc === null ? 'missing_or_stale_local' : 'verified_local'];
                if ($doc !== null) {
                    $keys[$id] = $doc['document_key'];
                }
            }
            if (count($ranks) !== count($keys)) {
                $reason = 'local_document_missing_or_stale';
            } elseif (count($keys) < 2) {
                $reason = 'insufficient_candidates';
            } else {
                $transport = true;
                $result = $this->client->rerankCatalog($query, $keys);
                $serviceMs = $result->latencyMs;
                $this->validate($keys, $result->reranked, $result->results);
                $model = $result->model;
                $slots = array_column($ranks, 'before');
                foreach ($result->results as $offset => $hit) {
                    $id = $hit['artwork_id'];
                    $ordered[$slots[$offset] - 1] = $id;
                    $ranks[$id] = [...$ranks[$id], 'after' => $slots[$offset], 'score' => $hit['score'], 'document' => 'current'];
                }
            }
        } catch (ConnectionException $error) {
            $reason = str_contains($error->getMessage(), 'cURL error 28') ? 'timeout' : 'service_unavailable';
        } catch (RequestException $error) {
            $reason = match ($error->response->status()) {
                404 => 'remote_document_missing',
                409 => 'remote_document_stale',
                408, 504 => 'timeout',
                422 => 'request_rejected',
                default => 'service_unavailable',
            };
        } catch (UnexpectedValueException) {
            $reason = 'invalid_response';
        } catch (\Throwable) {
            $reason = $transport ? 'service_unavailable' : 'verification_failed';
        }

        return ['ids' => $ordered, 'ranks' => $ranks, 'strategy' => $strategy, 'depth' => $depth,
            'model' => $model, 'transport' => $transport, 'fallback_reason' => $reason, 'service_ms' => $serviceMs,
            'ms' => AssistantSearchDiagnostics::ms($started), 'prefix_count' => count($prefix), 'verified_local_count' => count($keys)];
    }

    private function validate(array $keys, bool $reranked, array $hits): void
    {
        if (! $reranked || ! array_is_list($hits) || count($keys) !== count($hits)) {
            throw new UnexpectedValueException('ColBERT did not return a complete permutation');
        }
        foreach ($hits as $index => $hit) {
            $id = $hit['artwork_id'] ?? null;
            $score = $hit['score'] ?? null;
            if (! is_int($id) || ! isset($keys[$id]) || ($hit['rank'] ?? null) !== $index + 1
                || (! is_int($score) && ! is_float($score)) || ! is_finite($score)) {
                throw new UnexpectedValueException('Invalid ColBERT rank, score or candidate');
            }
            unset($keys[$id]);
        }
    }
}
