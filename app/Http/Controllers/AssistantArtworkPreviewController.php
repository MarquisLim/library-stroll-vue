<?php

namespace App\Http\Controllers;

use App\Services\Search\AssistantArtworkSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\Response;

class AssistantArtworkPreviewController extends Controller
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    private const HEADERS = [
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __invoke(Request $request, string $artwork, AssistantArtworkSearch $search): Response
    {
        try {
            $work = $search->visible(collect([$artwork]), $request->user())->first();
            $media = $work !== null && ! $work->is_adult ? $work->getFirstMedia('artworks') : null;
            if ($media === null || ! $media->hasGeneratedConversion('thumb')) {
                return response('', 404, self::HEADERS);
            }

            $path = $media->getPathRelativeToRoot('thumb');
            if ($path === $media->getPathRelativeToRoot()) {
                return response('', 404, self::HEADERS);
            }

            $disk = Storage::disk($media->conversions_disk ?: $media->disk);
            if (! $disk->fileExists($path) || $disk->size($path) > self::MAX_BYTES) {
                return response('', 404, self::HEADERS);
            }

            $bytes = $disk->get($path);
            if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
                return response('', 404, self::HEADERS);
            }

            $image = @getimagesizefromstring($bytes);
            $mime = $image['mime'] ?? null;
            if (! in_array($mime, ['image/webp', 'image/jpeg', 'image/png'], true)) {
                return response('', 404, self::HEADERS);
            }

            return response($bytes, 200, [...self::HEADERS, 'Content-Type' => $mime]);
        } catch (FilesystemException) {
            return response('', 404, self::HEADERS);
        }
    }
}
