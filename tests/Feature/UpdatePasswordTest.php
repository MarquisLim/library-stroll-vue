<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UpdatePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password') // Jetstream требует зашифрованный пароль
        ]);

        $this->actingAs($user);

        $response = $this->put('/user/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(200); // Проверяем успешный ответ

        // Проверяем, что пароль обновлён
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_current_password_must_be_correct(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password') // Jetstream требует зашифрованный пароль
        ]);

        $this->actingAs($user);

        $response = $this->put('/user/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertSessionHasErrors('current_password'); // Проверяем ошибку

        // Проверяем, что пароль не изменился
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_new_passwords_must_match(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password') // Jetstream требует зашифрованный пароль
        ]);

        $this->actingAs($user);

        $response = $this->put('/user/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('password'); // Проверяем ошибку

        // Проверяем, что пароль не изменился
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
