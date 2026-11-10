<?php

namespace App\Services\DemoContent;

class SourceRegistry
{
    public function get(string $source): ContentSource
    {
        return match ($source) {
            'met','artic' => new MuseumAdapter($source),'commons' => new CommonsAdapter,'nasa' => new NasaAdapter,'openverse' => new OpenverseAdapter,'smithsonian' => new SmithsonianAdapter
        };
    }

    public function allowed(ContentItem $item): bool
    {
        $license = mb_strtolower($item->license);

        return in_array($license, ['cc0', 'cc0 / public domain', 'public domain', 'public domain mark', 'cc0 1.0', 'nasa media usage guidelines'], true)
            || (preg_match('/^cc by(?: [1-4]\.[05])?$/', $license) && $item->creator_name && $item->attribution_text
                && preg_match('#^https://creativecommons\.org/licenses/by/[1-4]\.[05]/?$#', $item->license_url ?? '') && $item->source_url);
    }
}
