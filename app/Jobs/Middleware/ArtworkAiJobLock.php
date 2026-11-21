<?php

namespace App\Jobs\Middleware;

use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ArtworkAiJobLock
{
    public const RELEASE_DELAY = 30;

    public const LOCK_TTL = 660;

    // Reservations include lock contention. The separate exception limit covers failed executions.
    public const MAX_RESERVATIONS = 48;

    public const MAX_EXCEPTIONS = 3;

    public static function key(int $artworkId): string
    {
        return 'laravel-queue-overlap:artwork-ai-'.$artworkId;
    }

    public static function middleware(int $artworkId): WithoutOverlapping
    {
        return (new WithoutOverlapping(self::key($artworkId), self::RELEASE_DELAY, self::LOCK_TTL))
            ->withPrefix('')
            ->shared();
    }
}
