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
        // User exposes hasVerifiedEmail() without implementing MustVerifyEmail.
        abort_unless($request->user()->hasVerifiedEmail(), 403, 'Your email address is not verified.');
        if ($request->routeIs('assistant.conversations.*')) {
            abort_unless(config('ai.assistant_history_enabled'), 404);
            abort_if($request->isMethod('POST') && ! config('ai.assistant_enabled'), 404);

            return $next($request);
        }

        abort_unless(config('ai.assistant_enabled'), 404);
        if ($request->routeIs('assistant.answer')) {
            abort_unless(config('ai.assistant_rag_enabled'), 404);
        }

        return $this->throttle->handle($request, $next, 10, 1, 'assistant-search:');
    }
}
