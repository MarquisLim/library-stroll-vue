<?php

namespace App\Services\DemoContent;

use Illuminate\Support\Facades\File;

class Manifest
{
    public function path(int $seed, string $preset): string
    {
        return config('demo_content.root')."/manifests/$preset-$seed.json";
    }

    public function save(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname($path));
        $tmp = $path.'.tmp';
        File::put($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (! rename($tmp, $path)) {
            throw new \RuntimeException('Cannot save manifest');
        }
    }

    public function quotas(array $categories, int $target): array
    {
        $total = array_sum(array_column($categories, 'quota'));
        $result = [];
        $fractions = [];
        foreach ($categories as $key => $c) {
            $raw = $c['quota'] * $target / $total;
            $result[$key] = (int) floor($raw);
            $fractions[$key] = $raw - floor($raw);
        }
        arsort($fractions);
        $remaining = $target - array_sum($result);
        foreach (array_keys($fractions) as $key) {
            if ($remaining-- <= 0) {
                break;
            } $result[$key]++;
        }

        return $result;
    }
}
