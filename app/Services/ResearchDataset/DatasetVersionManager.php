<?php

namespace App\Services\ResearchDataset;

use Illuminate\Support\Facades\File;
use RuntimeException;

class DatasetVersionManager
{
    public const ARTIFACTS = ['manifest.json', 'artworks.jsonl', 'queries.jsonl', 'pool.jsonl', 'judgments.csv', 'judgment-context.json', 'artworks.csv', 'annotation-template.csv', 'annotation.html'];

    public function __construct(private DatasetFiles $files) {}

    public function prepare(string $name, array $selection, array $audit, int $seed, int $size, string $preset, bool $videos, bool $refresh): array
    {
        $root = $this->files->directory($name);
        $draft = $root.'/draft';
        $fingerprint = $this->files->hash(['snapshot' => $audit['snapshot_sha256'], 'size' => $size, 'seed' => $seed, 'preset' => $preset, 'videos' => $videos,
            'rules' => config('research_dataset'), 'themes' => config('demo_content.presets.'.$preset)]);
        if (is_dir($draft) && ! $refresh) {
            $m = $this->files->object($draft.'/manifest.json');
            if (($m['preparation_fingerprint'] ?? '') !== $fingerprint || ($m['corpus_sha256'] ?? '') !== hash_file('sha256', $draft.'/artworks.jsonl')) {
                throw new RuntimeException('Draft differs. Use a new name or --refresh to archive it; judgments will not be overwritten.');
            }

            return ['path' => $draft, 'existing' => true, 'manifest' => $m];
        }
        $temporary = $root.'/.draft-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($temporary);
        try {
            $queries = app(DatasetQueryBuilder::class)->build($selection['items'], $seed);
            $pool = app(RelevanceJudgmentService::class)->pool($selection['items'], $queries, config('research_dataset.pool_size'), $seed);
            $this->files->writeLines($temporary.'/artworks.jsonl', $selection['items']);
            $this->files->writeLines($temporary.'/queries.jsonl', $queries);
            $this->files->writeLines($temporary.'/pool.jsonl', $pool);
            $this->files->csv($temporary.'/judgments.csv', RelevanceJudgmentService::HEADER, []);
            $this->files->write($temporary.'/judgment-context.json', $this->files->json(['corpus_sha256' => hash_file('sha256', $temporary.'/artworks.jsonl'), 'queries' => []]));
            $manifest = ['schema_version' => config('research_dataset.schema_version'), 'name' => $name, 'status' => 'draft', 'created_at' => now()->utc()->toISOString(), 'seed' => $seed, 'requested_size' => $size, 'actual_size' => count($selection['items']), 'include_videos' => $videos, 'preset' => $preset,
                'selection_rules_version' => config('research_dataset.selection_rules_version'), 'query_rules_version' => config('research_dataset.query_rules_version'), 'pool_rules_version' => config('research_dataset.pool_rules_version'),
                'preparation_fingerprint' => $fingerprint, 'corpus_sha256' => hash_file('sha256', $temporary.'/artworks.jsonl'), 'item_ids' => array_column($selection['items'], 'dataset_item_id'),
                'query_membership' => array_map(fn ($q) => ['query_id' => $q['query_id'], 'intent_id' => $q['intent_id'], 'split' => $q['split']], $queries),
                'selection' => array_diff_key($selection, ['items' => true]), 'catalog_audit' => $audit,
                'validation_policy' => array_intersect_key(config('research_dataset'), array_flip(['minimum_images', 'minimum_queries', 'maximum_queries', 'minimum_judged_per_query', 'minimum_relevant_per_query', 'minimum_annotators', 'minimum_pool_coverage', 'relevant_grade', 'adjudicator_id', 'require_complete_catalog'])),
                'sampling_weights' => config('research_dataset.sampling_weights'), 'ai_metadata_included' => false];
            $this->files->write($temporary.'/manifest.json', $this->files->json($manifest)."\n");
            app(RelevanceJudgmentService::class)->export($temporary);
            $archive = null;
            if (is_dir($draft)) {
                $archive = $root.'/archives/draft-'.now()->format('Ymd-His-u');
                File::ensureDirectoryExists(dirname($archive));
                if (! rename($draft, $archive)) {
                    throw new RuntimeException('Cannot archive draft');
                }
            }
            if (! rename($temporary, $draft)) {
                if ($archive) {
                    rename($archive, $draft);
                } throw new RuntimeException('Cannot publish draft');
            }

            return ['path' => $draft, 'existing' => false, 'manifest' => $manifest];
        } finally {
            if (is_dir($temporary)) {
                File::deleteDirectory($temporary);
            }
        }
    }

    public function freeze(string $name, string $version, array $validation): string
    {
        $destination = $this->files->version($name, $version);
        if (file_exists($destination)) {
            throw new RuntimeException('Frozen version already exists; create a new version');
        }
        if (! $validation['ready_to_freeze']) {
            throw new RuntimeException('Dataset is a draft: catalog, queries or independent judgments are not ready');
        }
        $draft = $this->files->directory($name).'/draft';
        $temporary = dirname($destination).'/.freeze-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($temporary);
        try {
            foreach (self::ARTIFACTS as $file) {
                $this->files->write($temporary.'/'.$file, $this->files->read($draft.'/'.$file));
            }
            $manifest = $this->files->object($temporary.'/manifest.json');
            $manifest['status'] = 'frozen';
            $manifest['version'] = $version;
            $manifest['frozen_at'] = now()->utc()->toISOString();
            $manifest['validation_policy'] = $validation['policy'];
            $manifest['artifact_sha256'] = [];
            foreach (array_diff(self::ARTIFACTS, ['manifest.json']) as $file) {
                $manifest['artifact_sha256'][$file] = hash_file('sha256', $temporary.'/'.$file);
            }
            $this->files->write($temporary.'/manifest.json', $this->files->json($manifest)."\n");
            $this->files->write($temporary.'/validation.json', $this->files->json($validation)."\n");
            $this->files->write($temporary.'/README.md', "# {$manifest['name']} / $version\n\nFrozen at {$manifest['frozen_at']}. Schema {$manifest['schema_version']}; seed {$manifest['seed']}.\n\nRead artworks.jsonl, queries.jsonl and judgments.csv as the common corpus, intent-based query partitions and independent 0–3 assessments. Blank/missing pairs are unjudged, never zero. Pool selection is biased and is not exhaustive recall ground truth. No AI metadata or experimental metrics are included.\n\nMedia are referenced by public storage disk + relative path, stable source:external_id and SHA-256. Originals are not copied. Preserve a licensed, access-controlled backup of media and this version separately. Restore originals from that backup first. An upstream download is acceptable only if its SHA-256 is identical; do not silently replace changed bytes.\n\nVerify checksums.sha256 (for example sha256sum -c checksums.sha256), then dataset:validate --name={$manifest['name']} --dataset-version=$version. Live validation detects publication/provenance/media drift; --offline skips database lookup but still checks local referenced media. DatasetVersionManager::readVersion reads and checks the fixed artifacts without querying the demo manifest or database.\n\nVersion files must not be overwritten. Correct a draft and freeze a new version. Do not tune search on evaluation queries. RU/EN equivalents retain the same intent and partition.\n");
            $checksums = '';
            foreach ([...self::ARTIFACTS, 'validation.json', 'README.md'] as $file) {
                $checksums .= hash_file('sha256', $temporary.'/'.$file).'  '.$file."\n";
            }
            $this->files->write($temporary.'/checksums.sha256', $checksums);
            if (! rename($temporary, $destination)) {
                throw new RuntimeException('Cannot publish immutable version');
            }

            return $destination;
        } finally {
            if (is_dir($temporary)) {
                File::deleteDirectory($temporary);
            }
        }
    }

    /** Future ai:evaluate input contract. No database/demo-manifest/AI calls. */
    public function readVersion(string $name, string $version): array
    {
        $path = $this->files->version($name, $version);
        $expected = [...self::ARTIFACTS, 'validation.json', 'README.md'];
        $seen = [];
        foreach (explode("\n", trim($this->files->read($path.'/checksums.sha256'))) as $line) {
            if (! preg_match('/^([a-f0-9]{64})  ([a-zA-Z0-9.-]+)$/D', $line, $m) || ! in_array($m[2], $expected, true) || isset($seen[$m[2]]) || ! hash_equals($m[1], hash('sha256', $this->files->read($path.'/'.$m[2])))) {
                throw new RuntimeException('Version checksum/filename mismatch');
            }
            $seen[$m[2]] = true;
        }
        if (count($seen) !== count($expected)) {
            throw new RuntimeException('Incomplete version checksum list');
        }
        $manifest = $this->files->object($path.'/manifest.json');
        if (($manifest['status'] ?? '') !== 'frozen' || ($manifest['version'] ?? '') !== $version || ($manifest['name'] ?? '') !== $name) {
            throw new RuntimeException('Invalid frozen manifest');
        }
        if (($manifest['schema_version'] ?? null) !== 1 || ! ($this->files->object($path.'/validation.json')['ready_to_freeze'] ?? false)) {
            throw new RuntimeException('Unsupported schema or unvalidated research version');
        }

        return ['path' => $path, 'manifest' => $manifest, 'artworks' => $this->files->lines($path.'/artworks.jsonl'), 'queries' => $this->files->lines($path.'/queries.jsonl'), 'judgments' => $this->files->csvRows($path.'/judgments.csv'), 'pool' => $this->files->lines($path.'/pool.jsonl')];
    }
}
