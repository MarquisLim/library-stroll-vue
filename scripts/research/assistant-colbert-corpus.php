<?php

use App\Models\Artwork;
use App\Models\User;
use App\Services\AiService\ColbertCatalogDocument;
use App\Services\Search\AssistantArtworkSearch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement('SET SESSION TRANSACTION READ ONLY');
$started = hrtime(true);
$ids = Artwork::publiclyVisible()->where('type', 'image')->orderBy('id')->pluck('id');
$user = User::where('is_blocked', false)->orderBy('id')->firstOrFail();
$search = $app->make(AssistantArtworkSearch::class);
$formatter = $app->make(ColbertCatalogDocument::class);
$documents = $search->visible($ids, $user, withMetadata: true)->map($formatter->make(...))->filter()->values();
if ($documents->count() !== 511 || $ids->all() !== $documents->pluck('id')->all()) {
    throw new RuntimeException('The snapshot must cover all 511 current public images');
}
$bytes = json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
file_put_contents('/research/documents.json', $bytes);
$manifest = ['schema_version' => 1, 'document_count' => $documents->count(), 'documents_sha256' => hash('sha256', $bytes),
    'documents_bytes' => strlen($bytes), 'preparation_ms' => round((hrtime(true) - $started) / 1e6, 3),
    'php_peak_bytes' => memory_get_peak_usage(true)];
file_put_contents('/research/documents-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
