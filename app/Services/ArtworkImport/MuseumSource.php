<?php

namespace App\Services\ArtworkImport;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class MuseumSource
{
    public const SOURCES = ['artic', 'met'];

    private const PAGE_SIZE = 40;

    public function __construct(private readonly SourceTags $tags) {}

    /** @return Generator<SourceArtwork> */
    public function candidates(string $source, string $query, int $page, int $maxPages): Generator
    {
        for ($current = $page; $current < $page + $maxPages; $current++) {
            $params = $source === 'artic'
                ? ['q' => $query, 'page' => $current, 'limit' => self::PAGE_SIZE,
                    'query' => ['bool' => ['filter' => [
                        ['term' => ['is_public_domain' => true]],
                        ['exists' => ['field' => 'image_id']],
                    ]]],
                    'fields' => 'id,title,image_id,is_public_domain,artist_title,date_display,medium_display,subject_titles,style_titles,classification_title,artwork_type_title']
                : ['q' => $query ?: 'painting', 'hasImages' => 'true',
                    'offset' => ($current - 1) * self::PAGE_SIZE, 'limit' => self::PAGE_SIZE];

            $url = $source === 'artic'
                ? 'https://api.artic.edu/api/v1/artworks/search'
                : 'https://collectionapi.metmuseum.org/public/collection/v1.1/search';
            // JSON preserves boolean true. Bracket query encoding turns it into "1",
            // which the museum's Elasticsearch API rejects as a boolean.
            $queryParams = $source === 'artic'
                ? ['params' => json_encode($params, JSON_THROW_ON_ERROR)]
                : $params;
            $data = $this->http()->get($url, $queryParams)->throw()->json();
            $items = $source === 'artic' ? ($data['data'] ?? []) : ($data['objectIDs'] ?? []);
            if (! is_array($items) || $items === []) {
                return;
            }

            foreach ($items as $item) {
                if ($source === 'met') {
                    $response = $this->http()->get('https://collectionapi.metmuseum.org/public/collection/v1/objects/'.(int) $item);
                    if ($response->status() === 404) {
                        continue;
                    }
                    $item = $response->throw()->json();
                }
                $artwork = $source === 'artic' ? $this->fromArtic($item) : $this->fromMet($item);
                if ($artwork !== null) {
                    yield $artwork;
                }
            }
            if (count($items) < self::PAGE_SIZE) {
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
            imageUrl: 'https://www.artic.edu/iiif/2/'.rawurlencode($item['image_id']).'/full/843,/0/default.jpg',
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
