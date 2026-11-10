<?php

namespace App\Services\ArtworkImport;

use App\Services\DemoContent\SourceHttp;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class MuseumSource
{
    public const SOURCES = ['artic', 'met'];

    private const PAGE_SIZE = 40;

    public function __construct(private readonly SourceTags $tags) {}

    /** @return Generator<SourceArtwork> */
    public function candidates(string $source, string $query, int $page, int $maxPages, ?callable $skip = null): Generator
    {
        $pageSize = config("demo_content.sources.$source.page_size", self::PAGE_SIZE);
        for ($current = $page; $current < $page + $maxPages; $current++) {
            $params = $source === 'artic'
                ? ['q' => $query, 'page' => $current, 'limit' => $pageSize,
                    'query' => ['bool' => ['filter' => [
                        ['term' => ['is_public_domain' => true]],
                        ['exists' => ['field' => 'image_id']],
                    ]]],
                    'fields' => 'id,title,image_id,is_public_domain,artist_title,date_display,medium_display,subject_titles,style_titles,classification_title,artwork_type_title']
                : ['q' => $query ?: 'painting', 'hasImages' => 'true',
                    'offset' => ($current - 1) * $pageSize, 'limit' => $pageSize];

            $url = rtrim(config("demo_content.sources.$source.base_url"), '/').config("demo_content.sources.$source.search_endpoint");
            // JSON preserves boolean true. Bracket query encoding turns it into "1",
            // which the museum's Elasticsearch API rejects as a boolean.
            $queryParams = $source === 'artic'
                ? ['params' => json_encode($params, JSON_THROW_ON_ERROR)]
                : $params;
            $data = app(SourceHttp::class)->get($source, config("demo_content.sources.$source.search_endpoint"), $queryParams);
            $items = $source === 'artic' ? ($data['data'] ?? []) : ($data['objectIDs'] ?? []);
            if (! is_array($items) || $items === []) {
                return;
            }

            foreach ($items as $item) {
                $externalId = (string) ($source === 'met' ? $item : ($item['id'] ?? ''));
                if ($skip && $skip($externalId)) {
                    continue;
                }
                if ($source === 'met') {
                    try {
                        $item = app(SourceHttp::class)->get($source, str_replace('{id}', (string) (int) $item, config("demo_content.sources.$source.detail_endpoint")));
                    } catch (RequestException $e) {
                        if ($e->response->status() === 404) {
                            continue;
                        } throw $e;
                    }
                }
                $artwork = $source === 'artic' ? $this->fromArtic($item) : $this->fromMet($item);
                if ($artwork !== null) {
                    yield $artwork;
                }
            }
            if (count($items) < $pageSize) {
                return;
            }
        }
    }

    private function fromArtic(array $item): ?SourceArtwork
    {
        if (($item['is_public_domain'] ?? false) !== true || empty($item['image_id']) || empty($item['id'])) {
            return null;
        }

        return new SourceArtwork(
            source: 'artic', externalId: (string) (int) $item['id'],
            title: trim($item['title'] ?? '') ?: 'Untitled',
            artist: trim($item['artist_title'] ?? ''), date: trim($item['date_display'] ?? ''),
            medium: trim($item['medium_display'] ?? ''),
            sourceUrl: 'https://www.artic.edu/artworks/'.(int) $item['id'],
            imageUrl: str_replace('{image_id}', rawurlencode($item['image_id']), config('demo_content.sources.artic.media_url_template')),
            tags: $this->tags->normalize([
                ...($item['subject_titles'] ?? []), ...($item['style_titles'] ?? []),
                $item['classification_title'] ?? '', $item['artwork_type_title'] ?? '',
            ]),
        );
    }

    private function fromMet(array $item): ?SourceArtwork
    {
        $image = ($item['primaryImageSmall'] ?? '') ?: ($item['primaryImage'] ?? '');
        if (($item['isPublicDomain'] ?? false) !== true || ! $image || empty($item['objectID'])) {
            return null;
        }

        return new SourceArtwork(
            source: 'met', externalId: (string) (int) $item['objectID'],
            title: trim($item['title'] ?? '') ?: 'Untitled',
            artist: trim($item['artistDisplayName'] ?? ''), date: trim($item['objectDate'] ?? ''),
            medium: trim($item['medium'] ?? ''),
            sourceUrl: 'https://www.metmuseum.org/art/collection/search/'.(int) $item['objectID'],
            imageUrl: $image,
            tags: $this->tags->normalize([
                ...array_column($item['tags'] ?? [], 'term'),
                $item['classification'] ?? '', $item['objectName'] ?? '',
            ]),
        );
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->withUserAgent('LibraryStroll artwork importer')
            ->connectTimeout(10)->timeout(30)->retry(2, 500, throw: false);
    }
}
