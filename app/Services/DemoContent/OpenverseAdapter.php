<?php

namespace App\Services\DemoContent;

class OpenverseAdapter implements ContentSource
{
    public function candidates(string $query, string $type): iterable
    {
        if ($type !== 'image') {
            return;
        }
        for ($page = 1; $page <= config('demo_content.sources.openverse.max_pages'); $page++) {
            $data = app(SourceHttp::class)->get('openverse', config('demo_content.sources.openverse.search_endpoint'), ['q' => $query, 'page' => $page, 'page_size' => config('demo_content.sources.openverse.page_size', 25), 'license' => 'cc0,pdm,by']);
            if (empty($data['results'])) {
                break;
            }
            foreach ($data['results'] as $r) {
                yield new ContentItem('openverse', $r['id'], 'image', $r['foreign_landing_url'] ?? '', $r['url'] ?? '', ContentItem::text($r['title'] ?? ''),
                    ContentItem::text($r['creator'] ?? '') ?: null, match ($r['license'] ?? '') {
                        'cc0' => 'CC0','pdm' => 'Public Domain','by' => 'CC BY',default => 'unverified'
                    },
                    $r['license_url'] ?? null, array_column($r['tags'] ?? [], 'name'), creator_url: $r['creator_url'] ?? null, attribution_text: ContentItem::text($r['attribution'] ?? '') ?: null, original_metadata: array_intersect_key($r, array_flip(['id', 'title', 'creator', 'creator_url', 'license', 'license_url', 'tags', 'attribution', 'foreign_landing_url'])));
            }
        }
    }
}
