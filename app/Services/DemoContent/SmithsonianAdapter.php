<?php

namespace App\Services\DemoContent;

class SmithsonianAdapter implements ContentSource
{
    public function candidates(string $query, string $type): iterable
    {
        if ($type !== 'image' || ! config('demo_content.sources.smithsonian.api_key')) {
            return;
        }
        for ($page = 0; $page < config('demo_content.sources.smithsonian.max_pages'); $page++) {
            $size = config('demo_content.sources.smithsonian.page_size', 25);
            $data = app(SourceHttp::class)->get('smithsonian', config('demo_content.sources.smithsonian.search_endpoint'), ['api_key' => config('demo_content.sources.smithsonian.api_key'), 'q' => $query, 'start' => $page * $size, 'rows' => $size]);
            if (empty($data['response']['rows'])) {
                break;
            }
            foreach ($data['response']['rows'] as $row) {
                $detail = app(SourceHttp::class)->get('smithsonian', str_replace('{id}', rawurlencode($row['id']), config('demo_content.sources.smithsonian.detail_endpoint')), ['api_key' => config('demo_content.sources.smithsonian.api_key')]);
                $r = $detail['response'] ?? $row;
                $content = $r['content'] ?? [];
                $d = $content['descriptiveNonRepeating'] ?? [];
                foreach ($d['online_media']['media'] ?? [] as $media) {
                    if (($media['type'] ?? '') !== 'Images') {
                        continue;
                    }
                    yield new ContentItem('smithsonian', (string) $r['id'], 'image', $d['record_link'] ?? '', $media['content'] ?? '', ContentItem::text($r['title'] ?? ''),
                        ContentItem::text($content['freetext']['name'][0]['content'] ?? '') ?: null,
                        ($media['usage']['access'] ?? '') === 'CC0' ? 'CC0' : 'unverified', 'https://creativecommons.org/publicdomain/zero/1.0/',
                        array_column($content['freetext']['topic'] ?? [], 'content'), original_metadata: ['id' => $r['id'], 'title' => $r['title'] ?? null, 'usage' => $media['usage'] ?? [], 'topics' => $content['freetext']['topic'] ?? []]);
                    break;
                }
            }
        }
    }
}
