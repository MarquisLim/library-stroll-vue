<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Features;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_accounts_can_be_deleted(): void
    {
        if (! Features::hasAccountDeletionFeatures()) {
            $this->markTestSkipped('Account deletion is not enabled.');
        }

        $user = User::factory()->create([
            'password' => bcrypt('password') // Хешируем пароль
        ]);

        $this->actingAs($user)
            ->withoutMiddleware() // 🔹 Отключаем CSRF
            ->delete('/user', [
                'password' => 'password',
            ])->assertRedirect('/');

        // Проверяем, что пользователь удалён
        $this->assertSoftDeleted('users', ['id' => $user->id]); // Если SoftDeletes
    }

    public function test_correct_password_must_be_provided_before_account_can_be_deleted(): void
    {
        if (! Features::hasAccountDeletionFeatures()) {
            $this->markTestSkipped('Account deletion is not enabled.');
        }

        $user = User::factory()->create([
            'password' => bcrypt('password') // Хешируем пароль
        ]);

        $this->actingAs($user)
            ->withoutMiddleware() // 🔹 Отключаем CSRF
            ->delete('/user', [
                'password' => 'wrong-password',
            ])->assertSessionHasErrors();

        // Проверяем, что пользователь остался в БД
        $this->assertNotNull($user->fresh());
    }
}
