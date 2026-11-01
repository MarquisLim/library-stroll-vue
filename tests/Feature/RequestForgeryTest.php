<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestForgeryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CSRF protection is otherwise bypassed automatically in tests.
        $this->app->detectEnvironment(fn () => 'local');
    }

    public function test_login_rejects_a_request_without_origin_or_csrf_token(): void
    {
        $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ])->assertStatus(419);

        $this->assertGuest();
    }

    public function test_clients_without_fetch_metadata_can_still_login_with_a_csrf_token(): void
    {
        $user = User::factory()->create();
        $token = str_repeat('a', 40);

        $this->withSession(['_token' => $token])->post('/login', [
            '_token' => $token,
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }
}
