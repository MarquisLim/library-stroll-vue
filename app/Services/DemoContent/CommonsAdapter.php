<?php

namespace App\Services\DemoContent;

class CommonsAdapter implements ContentSource
{
    public function candidates(string $query, string $type): iterable
    {
        $continue = [];
        for ($page = 0; $page < config('demo_content.sources.commons.max_pages'); $page++) {
            $data = app(SourceHttp::class)->get('commons', config('demo_content.sources.commons.search_endpoint'), [
                'action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrsearch' => $query.' filetype:'.($type === 'video' ? 'video' : 'bitmap'),
                'gsrnamespace' => 6, 'gsrlimit' => config('demo_content.sources.commons.page_size', 25), 'prop' => 'imageinfo|categories', 'cllimit' => 50, 'iiprop' => 'url|extmetadata|mime', ...$continue]);
            foreach ($data['query']['pages'] ?? [] as $row) {
                $i = $row['imageinfo'][0] ?? [];
                $m = $i['extmetadata'] ?? [];
                $license = ContentItem::text($m['LicenseShortName']['value'] ?? '');
                $creator = ContentItem::text($m['Artist']['value'] ?? '');
                $licenseUrl = $m['LicenseUrl']['value'] ?? null;
                if (str_starts_with($licenseUrl ?? '', '//')) {
                    $licenseUrl = 'https:'.$licenseUrl;
                }
                if (! $licenseUrl && mb_strtolower($license) === 'public domain') {
                    $licenseUrl = 'https://creativecommons.org/publicdomain/mark/1.0/';
                }
                $attribution = ContentItem::text($m['Attribution']['value'] ?? $creator);
                $tags = array_map(fn ($c) => preg_replace('/^Category:/', '', $c['title']), $row['categories'] ?? []);
                yield new ContentItem('commons', (string) $row['pageid'], $type, $i['descriptionurl'] ?? '', $i['url'] ?? '',
                    ContentItem::text($m['ObjectName']['value'] ?? $row['title']), $creator ?: null, $license, $licenseUrl, $tags,
                    categories: $tags, attribution_text: $attribution ?: null, creation_date: ContentItem::text($m['DateTimeOriginal']['value'] ?? '') ?: null,
                    original_metadata: ['extmetadata' => array_intersect_key($m, array_flip(['Artist', 'LicenseShortName', 'LicenseUrl', 'Attribution', 'DateTimeOriginal'])), 'mime' => $i['mime'] ?? null]);
            }
            if (empty($data['continue'])) {
                break;
            }
            $continue = $data['continue'];
        }
    }
}
