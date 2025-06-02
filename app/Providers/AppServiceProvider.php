<?php

namespace App\Providers;

use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(UrlGenerator $url)
    {
        if (env('APP_ENV') == 'production') {
            $url->forceScheme('https');
        }
        if (app()->environment('local')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('http');
        }

        Inertia::share([
            'conversationIds' => function () {
                $user = auth()->user();
                return $user ? $user->conversations()->pluck('id')->all() : [];
            },
            'unreadCount' => function () {
                $user = auth()->user();
                if (! $user) {
                    return 0;
                }
                return $user
                    ->conversations()
                    ->withPivot('last_read_at')
                    ->get()
                    ->sum(function ($conv) use ($user) {
                        return $conv->messages()
                            ->where('user_id', '!=', $user->id)
                            ->when($conv->pivot->last_read_at, function ($query, $dt) {
                                return $query->where('created_at', '>', $dt);
                            })
                            ->count();
                    });
            },
            'unreadNotificationsCount' => fn() => auth()->user()?->unreadNotifications()->count() ?? 0,
        ]);
    }
}
