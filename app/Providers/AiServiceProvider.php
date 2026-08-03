<?php

namespace App\Providers;

use App\Contracts\AiServiceClient;
use App\Services\AiService\HttpAiServiceClient;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiServiceClient::class, function () {
            return new HttpAiServiceClient();
        });
    }
}
