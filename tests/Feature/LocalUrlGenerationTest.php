<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

class LocalUrlGenerationTest extends TestCase
{
    public function test_local_assets_and_routes_follow_request_host_instead_of_app_url(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['app.url' => 'http://localhost:8088']);
        $url = app('url');

        foreach (['http://127.0.0.1:8088', 'http://localhost:8088'] as $origin) {
            $url->setRequest(Request::create($origin.'/'));
            (new AppServiceProvider($this->app))->boot($url);

            $this->assertSame($origin.'/build/assets/app.js', $url->asset('build/assets/app.js'));
            $this->assertSame($origin.'/login', $url->to('/login'));
        }
    }
}
