<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_settings_page_loads(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/user/profile');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Profile/Show'));
    }
}
