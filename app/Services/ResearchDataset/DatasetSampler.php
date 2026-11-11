<?php

namespace App\Services\ResearchDataset;

use App\Services\DemoContent\Manifest;

class DatasetSampler
{
    public function sample(array $items, int $size, int $seed, string $preset): array
    {
        $targets = app(Manifest::class)->quotas(config('demo_content.presets.'.$preset), $size);
        usort($items, fn ($a, $b) => strcmp(hash('sha256', $seed.':'.$a['dataset_item_id']), hash('sha256', $seed.':'.$b['dataset_item_id'])) ?: strcmp($a['dataset_item_id'], $b['dataset_item_id']));
        $selected = [];
        $counts = [];
        $frequency = [];
        $available = array_count_values(array_column($items, 'category'));
        while ($items && count($selected) < $size) {
            $best = null;
            $bestScore = PHP_FLOAT_MAX;
            foreach ($items as $key => $i) {
                $category = $i['category'];
                $target = $targets[$category] ?? 1;
                $score = 10000 * (($counts[$category] ?? 0) / max(1, $target));
                foreach (config('research_dataset.sampling_weights') as $field => $weight) {
                    $value = $i['strata'][$field] ?? $i[$field] ?? null;
                    if ($value !== null && $value !== '') {
                        $score += $weight * ($frequency[$field][mb_strtolower($value)] ?? 0);
                    }
                }
                if ($score < $bestScore) {
                    $best = $key;
                    $bestScore = $score;
                }
            }
            $i = $items[$best];
            unset($items[$best]);
            $selected[] = $i;
            $counts[$i['category']] = ($counts[$i['category']] ?? 0) + 1;
            foreach (config('research_dataset.sampling_weights') as $field => $weight) {
                $value = $i['strata'][$field] ?? $i[$field] ?? null;
                if ($value !== null && $value !== '') {
                    $value = mb_strtolower($value);
                    $frequency[$field][$value] = ($frequency[$field][$value] ?? 0) + 1;
                }
            }
        }
        $shortfall = [];
        foreach ($targets as $category => $n) {
            if (($available[$category] ?? 0) < $n) {
                $shortfall[$category] = $n - ($available[$category] ?? 0);
            }
        }
        usort($selected, fn ($a, $b) => strcmp($a['dataset_item_id'], $b['dataset_item_id']));

        return ['items' => $selected, 'targets' => $targets, 'distribution' => $counts, 'category_shortfall' => $shortfall, 'size_shortfall' => max(0, $size - count($selected)), 'strata' => $frequency];
    }
}
