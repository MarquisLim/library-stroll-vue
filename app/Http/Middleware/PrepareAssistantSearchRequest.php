<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrepareAssistantSearchRequest
{
    private const MAX_BODY_BYTES = 4096;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('assistant/conversations', 'assistant/conversations/*')
            && (! $request->isMethod('POST') || ! $request->is('assistant/search', 'assistant/answer'))) {
            return $next($request);
        }

        $request->headers->set('Accept', 'application/json');

        $response = (int) $request->server('CONTENT_LENGTH') > self::MAX_BODY_BYTES
            || strlen($request->getContent()) > self::MAX_BODY_BYTES
            ? response()->json(['message' => 'Request body must not exceed 4 KiB.'], 413)
            : $next($request);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
