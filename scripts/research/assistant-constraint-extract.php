<?php

use App\Services\AiService\AssistantQueryStructure;
use App\Services\AiService\AssistantQueryUnderstanding;
use App\Services\Search\AssistantSearchDiagnostics;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $split = $argv[1] ?? '';
    $data = json_decode(file_get_contents(__DIR__.'/assistant-constraint-cases.json'), true, flags: JSON_THROW_ON_ERROR);
    $cases = match ($split) {
        'development', 'evaluation' => $data[$split],
        'regression-1c', 'regression-1d1' => array_map(
            fn ($row) => ['id' => $row[0], 'query' => $row[3]],
            json_decode(file_get_contents(__DIR__.($split === 'regression-1c' ? '/assistant-hybrid-cases.json' : '/assistant-colbert-cases.json')),
                true, flags: JSON_THROW_ON_ERROR)['evaluation'],
        ),
        default => throw new InvalidArgumentException('Unknown split'),
    };
    $fixtureHash = hash_file('sha256', __DIR__.'/assistant-constraint-cases.json');
    $freeze = json_decode(file_get_contents('/research/experiment-freeze.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($fixtureHash !== $freeze['dataset']) {
        throw new RuntimeException('Frozen query input changed');
    }
    foreach ([...$data['development'], ...$data['evaluation']] as $row) {
        if (AssistantQueryStructure::decode(json_encode($row['oracle'], JSON_THROW_ON_ERROR), $row['query']) === null) {
            throw new RuntimeException('Invalid oracle annotation: '.$row['id']);
        }
    }
    config(['ai.service_url' => 'http://library-stroll-constraints-qu:8001']);
    $understanding = $app->make(AssistantQueryUnderstanding::class);
    $rows = [];
    foreach ($cases as ['id' => $id, 'query' => $query]) {
        $start = hrtime(true);
        $parsed = $understanding->extract($query);
        $rows[$id] = ['query' => $query, 'ms' => AssistantSearchDiagnostics::ms($start), ...$parsed];
        fwrite(STDERR, $split.' '.$id.' '.$parsed['validation']."\n");
        if (in_array($parsed['fallback_reason'], ['service_key_missing', 'service_disabled'], true)) {
            throw new RuntimeException('Query Understanding is not configured in the research service');
        }
    }
    file_put_contents('/research/extraction-'.$split.'.json',
        json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
