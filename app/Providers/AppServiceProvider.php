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
                return $user
                    ? $user->conversations()->pluck('id')->all()
                    : [];
            },
            'unreadCount' => function () {
                $user = auth()->user();
                return $user
                    ? $user->conversations()
                        ->withPivot('last_read_at')
                        ->get()
                        ->sum(fn($conv) => $conv->messages()
                            ->where('user_id','!=',$user->id)
                            ->when($conv->pivot->last_read_at,
                                fn($q,$dt) => $q->where('created_at','>',$dt)
                            )->count()
                        )
                    : 0;
            },
        ]);
    }
}
