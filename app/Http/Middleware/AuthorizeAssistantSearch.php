<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeAssistantSearch
{
    public function __construct(private ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('assistant.conversations.*')) {
            abort_unless(config('ai.assistant_history_enabled'), 404);
            abort_if($request->isMethod('POST') && ! config('ai.assistant_enabled'), 404);

            if ($request->routeIs('assistant.conversations.turns.store')) {
                abort_unless(config('ai.assistant_rag_enabled'), 404);

                return $this->throttle->handle($request, $next, 10, 1, 'assistant-search:');
            }

            return $next($request);
        }

        abort_unless(config('ai.assistant_enabled'), 404);
        if ($request->routeIs('assistant.index', 'assistant.artworks.preview')) {
            return $next($request);
        }

        if ($request->routeIs('assistant.answer')) {
            abort_unless(config('ai.assistant_rag_enabled'), 404);
        }

        return $this->throttle->handle($request, $next, 10, 1, 'assistant-search:');
    }
}
