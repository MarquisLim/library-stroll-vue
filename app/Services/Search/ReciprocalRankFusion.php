<?php

namespace App\Services\Search;

class ReciprocalRankFusion
{
    /**
     * Merge multiple ranked lists using Reciprocal Rank Fusion.
     *
     * @param  array<int, array<int, int|float>>  $rankedLists  Each list: [[id, score], ...]
     * @param  int  $k  RRF constant (typically 60)
     * @param  int  $limit  Max results to return
     * @return array<int, array{id: int, score: float}>  Fused results sorted by RRF score desc
     */
    public function fuse(array $rankedLists, int $k = 60, int $limit = 20): array
    {
        $scores = [];

        foreach ($rankedLists as $list) {
            foreach ($list as $rank => $item) {
                $id = is_array($item) ? $item['id'] : $item;
                $position = $rank + 1;
                $rrfScore = 1.0 / ($k + $position);

                if (! isset($scores[$id])) {
                    $scores[$id] = 0.0;
                }
                $scores[$id] += $rrfScore;
            }
        }

        arsort($scores);

        $results = [];
        foreach (array_slice($scores, 0, $limit, true) as $id => $score) {
            $results[] = ['id' => $id, 'score' => round($score, 6)];
        }

        return $results;
    }
}
