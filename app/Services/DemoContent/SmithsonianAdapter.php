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
            $searchQuery = str_replace('{query}', $query, config('demo_content.sources.smithsonian.search_query_template', '{query}'));
            $data = app(SourceHttp::class)->get('smithsonian', config('demo_content.sources.smithsonian.search_endpoint'), ['api_key' => config('demo_content.sources.smithsonian.api_key'), 'q' => $searchQuery, 'start' => $page * $size, 'rows' => $size]);
            if (empty($data['response']['rows'])) {
                break;
            }
            foreach ($data['response']['rows'] as $row) {
                if (isset(app(SourceHttp::class)->known['smithsonian:'.$row['id']])) {
                    app(SourceHttp::class)->record('smithsonian', 'known_id_skips');

                    continue;
                }
                $detail = app(SourceHttp::class)->get('smithsonian', str_replace('{id}', rawurlencode($row['id']), config('demo_content.sources.smithsonian.detail_endpoint')), ['api_key' => config('demo_content.sources.smithsonian.api_key')]);
                $r = $detail['response'] ?? $row;
                if (isset($r['content']['content'])) {
                    $r = $r['content'];
                }
                $content = $r['content'] ?? [];
                $d = $content['descriptiveNonRepeating'] ?? [];
                $creator = null;
                foreach ($content['freetext']['name'] ?? [] as $name) {
                    if (! isset($name['label']) || preg_match('/artist|maker|creator|photographer|painter|author/i', $name['label'])) {
                        $creator = ContentItem::text($name['content'] ?? '') ?: null;
                        break;
                    }
                }
                $mediaItems = $d['online_media']['media'] ?? [];
                usort($mediaItems, fn ($a, $b) => (($b['usage']['access'] ?? '') === 'CC0') <=> (($a['usage']['access'] ?? '') === 'CC0'));
                foreach ($mediaItems as $media) {
                    if (($media['type'] ?? '') !== 'Images') {
                        continue;
                    }
                    $url = ! empty($media['idsId']) ? str_replace('{image_id}', rawurlencode($media['idsId']), config('demo_content.sources.smithsonian.media_url_template')) : ($media['content'] ?? '');
                    $tags = array_merge(array_column($content['freetext']['topic'] ?? [], 'content'), $content['indexedStructured']['topic'] ?? [], $content['indexedStructured']['object_type'] ?? [], $content['indexedStructured']['culture'] ?? []);
                    yield new ContentItem('smithsonian', (string) $r['id'], 'image', $d['record_link'] ?? '', $url, ContentItem::text($r['title'] ?? ''),
                        $creator,
                        ($media['usage']['access'] ?? '') === 'CC0' ? 'CC0' : 'unverified', 'https://creativecommons.org/publicdomain/zero/1.0/',
                        $tags, original_metadata: ['id' => $r['id'], 'title' => $r['title'] ?? null, 'usage' => $media['usage'] ?? [], 'topics' => $tags]);
                    break;
                }
            }
            if (count($data['response']['rows']) < $size) {
                break;
            }
        }
    }
}
