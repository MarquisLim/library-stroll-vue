<?php

use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\Search\AssistantSearchDiagnostics;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$output = '/research';
$check = in_array('--check', $argv, true);
$ids = [282, 286, 285, 292, 347, 298, 323, 322, 252, 275, 294, 506, 211, 212, 59, 480, 509, 445, 419, 83, 353, 325];
$fields = ['summary', 'objects', 'themes', 'colors', 'styles', 'tags_ru', 'tags_en'];
$write = static function (string $name, array $data) use ($output): void {
    file_put_contents($output.'/'.$name.'.json', json_encode($data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
};

DB::statement('SET TRANSACTION READ ONLY');
[$report, $selected, $images] = DB::transaction(function () use ($app, $ids, $fields) {
    $works = Artwork::publiclyVisible()->where('type', 'image')->orderBy('id')
        ->with(['aiMetadata', 'media', 'tags' => fn ($query) => $query->orderBy('name')])->get();
    $runs = AiProcessingRun::whereIn('id', AiProcessingRun::selectRaw('MAX(id)')
        ->where('operation', 'analyze_and_index')->where('status', 'success')
        ->whereIn('artwork_id', $works->pluck('id'))->groupBy('artwork_id'))->get()->keyBy('artwork_id');
    $indexing = $app->make(ArtworkIndexingService::class);
    $availability = array_fill_keys($fields, 0);
    $cues = ['explicit_count' => '/\b(?:single|one|two|three|four|five|pair of|[2-9])\s+(?:\pL+\s+)?(?:birds?|people|figures?|persons?|animals?|objects?|children|men|women|houses?|trees?)\b/iu',
        'background_word' => '/\bbackground\b/iu', 'spatial_words' => '/\b(?:above|below|under|over|behind|beside|next to|in front of|left of|right of)\b/iu',
        'absence_words' => '/\b(?:without|no visible|no sign of|absence of)\b/iu'];
    $cueCounts = array_fill_keys(array_keys($cues), 0);
    $digest = hash_init('sha256');
    $unverified = [];
    $selected = [];
    $images = [];
    foreach ($works as $work) {
        $meta = $work->aiMetadata;
        $run = $runs->get($work->id);
        $media = $work->getFirstMedia('artworks');
        $revision = $indexing->contentHash($work, sortedTagNames: $work->tags->pluck('name')->all());
        if (! $meta?->isIndexed() || $meta->content_hash !== $revision || $meta->source_media_uuid !== $media?->uuid
            || $meta->vision_model !== 'qwen3-vl:4b-instruct' || $meta->embedding_model !== 'qwen3-embedding:0.6b'
            || $meta->embedding_dimension !== 1024 || ($run->parameters['analysis_source'] ?? null) !== 'vision'
            || ($run->parameters['content_hash'] ?? null) !== $revision || ! $meta->indexed_at || ! $run?->finished_at
            || abs($run->finished_at->timestamp - $meta->indexed_at->timestamp) > 2) {
            $unverified[] = $work->id;
            continue;
        }
        $metadata = $meta->only($fields);
        hash_update($digest, json_encode([$work->id, $revision, $media->uuid, $metadata], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        foreach ($metadata as $field => $value) {
            $availability[$field] += (int) ! empty($value);
        }
        foreach ($cues as $kind => $regex) {
            $cueCounts[$kind] += (int) preg_match($regex, $meta->summary);
        }
        if (in_array($work->id, $ids, true)) {
            $selected[$work->id] = array_map(fn ($value) => is_array($value)
                ? array_map(fn ($item) => AssistantSearchDiagnostics::publicText($item, 160), $value)
                : AssistantSearchDiagnostics::publicText($value, 1200), $metadata);
            $images[$work->id] = $media;
        }
    }

    return [['public_images' => $works->count(), 'current_vision' => $works->count() - count($unverified),
        'unverified' => $unverified, 'field_availability' => $availability, 'indicative_summary_cues' => $cueCounts,
        'metadata_sha256' => hash_final($digest)], $selected, $images];
});

if ($report['current_vision'] !== 511 || count($selected) !== count($ids)) {
    throw new RuntimeException('The source catalog could not be verified');
}
$write($check ? 'vision-corpus-after' : 'vision-corpus-before', $report);
if ($check) {
    exit;
}
$write('vision-selected', $selected);

foreach (array_chunk($ids, 8) as $page => $group) {
    $width = 250;
    $height = 194;
    $sheet = imagecreatetruecolor(4 * $width, 2 * $height);
    imagefill($sheet, 0, 0, 0xffffff);
    foreach ($group as $position => $id) {
        $media = $images[$id];
        $source = imagecreatefromstring(Storage::disk($media->disk)->get($media->getPathRelativeToRoot()));
        if ($source === false) {
            throw new RuntimeException('Could not decode control media');
        }
        $x = $position % 4 * $width;
        $y = intdiv($position, 4) * $height;
        imagefilledrectangle($sheet, $x + 2, $y + 2, $x + $width - 2, $y + $height - 2, 0xe8e8e8);
        imagestring($sheet, 5, $x + 7, $y + 4, '#'.$id, 0x111111);
        $ratio = min(($width - 10) / imagesx($source), ($height - 30) / imagesy($source));
        $w = max(1, (int) (imagesx($source) * $ratio));
        $h = max(1, (int) (imagesy($source) * $ratio));
        imagecopyresampled($sheet, $source, $x + intdiv($width - $w, 2), $y + 24, 0, 0, $w, $h, imagesx($source), imagesy($source));
    }
    imagepng($sheet, $output.'/vision-sheet-'.($page + 1).'.png');
}
