<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Laravel\Fortify\Features;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => function () use ($request) {
                    if (! $user = $request->user()) {
                        return null;
                    }

                    $roles = [];
                    try {
                        $roles = $user->getRoleNames();
                    } catch (\Throwable) {
                        // roles table may be unavailable during setup
                    }

                    return array_merge(
                        $user->only('id', 'name', 'email', 'profile_photo_url'),
                        [
                            'roles' => $roles,
                            'two_factor_enabled' => Features::canManageTwoFactorAuthentication()
                                && ! is_null($user->two_factor_secret),
                        ],
                    );
                },
            ],
        ]);
    }
}
