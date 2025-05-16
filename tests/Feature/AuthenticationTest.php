<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Session;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password')
        ]);

        // Имитируем CSRF-токен
        Session::start();

        $response = $this->post('/login', [
            '_token' => csrf_token(),
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('home', absolute: false));

        // Проверяем, что пользователь вошёл
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password') // Jetstream требует зашифрованный пароль
        ]);

        // Имитируем CSRF-токен
        Session::start();

        $response = $this->post('/login', [
            '_token' => csrf_token(),
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        // Проверяем, что пользователь остался гостем
        $this->assertGuest();
    }
}
