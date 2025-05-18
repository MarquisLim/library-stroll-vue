<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Handler extends ExceptionHandler
{
    // те же $levels, $dontReport, $dontFlash, что и в стандартном Handler

    public function register(): void
    {
        $this->renderable(function (HttpException $e, Request $request) {
            if ($request->header('X-Inertia')) {
                $status = $e->getStatusCode();
                // Выбираем страницу по коду
                $page = match ($status) {
                    403 => 'Errors/Forbidden',
                    404 => 'Errors/NotFound',
                    default => 'Errors/ServerError',
                };
                return Inertia::render($page, ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }
        });
    }
}
