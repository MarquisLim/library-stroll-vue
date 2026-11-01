<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class UseRequestStorageUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $original = config('filesystems.disks.public.url');
        $applicationStorage = rtrim(config('app.url'), '/').'/storage';

        // Local uploads follow the browser origin. Explicit CDN URLs and S3 stay intact.
        if (config('filesystems.disks.public.driver') !== 'local' || $original !== $applicationStorage) {
            return $next($request);
        }

        $disk = Storage::disk('public');
        $requestUrl = $request->getBaseUrl().'/storage';
        $requestDisk = Storage::build(['driver' => 'local', ...$disk->getConfig(), 'url' => $requestUrl]);
        config(['filesystems.disks.public.url' => $requestUrl]);
        Storage::set('public', $requestDisk);

        try {
            return $next($request);
        } finally {
            // Do not leak request-specific settings into later requests or console work.
            config(['filesystems.disks.public.url' => $original]);
            Storage::set('public', $disk);
        }
    }
}
