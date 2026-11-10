<?php

namespace App\Services\DemoContent;

class NasaAdapter implements ContentSource
{
    public function candidates(string $query, string $type): iterable
    {
        for ($page = 1; $page <= config('demo_content.sources.nasa.max_pages'); $page++) {
            $data = app(SourceHttp::class)->get('nasa', config('demo_content.sources.nasa.search_endpoint'), ['q' => $query, 'media_type' => $type, 'page' => $page, 'page_size' => config('demo_content.sources.nasa.page_size', 25)]);
            $rows = $data['collection']['items'] ?? [];
            if (! $rows) {
                break;
            }
            foreach ($rows as $row) {
                $d = $row['data'][0] ?? [];
                $id = $d['nasa_id'] ?? '';
                $credit = ContentItem::text($d['photographer'] ?? $d['secondary_creator'] ?? $d['center'] ?? '');
                // Only explicit NASA credits, no blanket CC0 assignment or third-party credits.
                $safe = preg_match('/^(NASA|JSC|JPL|GSFC|KSC|MSFC|GRC|ARC|AFRC|LaRC)(\s|$)/i', $credit)
                    && ! preg_match('/copyright|©|ESA|Getty|Reuters|SpaceX|STScI|Caltech/i', json_encode($d));
                $assets = $id ? app(SourceHttp::class)->get('nasa', str_replace('{id}', rawurlencode($id), config('demo_content.sources.nasa.detail_endpoint'))) : [];
                $urls = array_column($assets['collection']['items'] ?? [], 'href');
                $urls = array_values(array_filter($urls, fn ($url) => preg_match($type === 'video' ? '/\.mp4$/i' : '/\.(jpg|jpeg|png)$/i', $url)));
                usort($urls, fn ($a, $b) => strcmp($a, $b));
                yield new ContentItem('nasa', $id, $type, 'https://images.nasa.gov/details/'.$id, $urls[0] ?? '', ContentItem::text($d['title'] ?? ''),
                    $credit ?: null, $safe ? 'NASA Media Usage Guidelines' : 'unverified', config('demo_content.nasa_license_url'),
                    $d['keywords'] ?? [], creation_date: $d['date_created'] ?? null, attribution_text: $credit ?: null, original_metadata: array_intersect_key($d, array_flip(['nasa_id', 'title', 'keywords', 'center', 'photographer', 'secondary_creator', 'date_created'])));
            }
        }
    }
}
