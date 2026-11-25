<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssistantPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['ai.assistant_enabled' => true, 'ai.enabled' => true]);
    }

    public function test_verified_user_can_open_page_without_calling_ai_or_spending_search_quota(): void
    {
        Http::fake();
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $this->get(route('assistant.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Assistant/Index')
            ->where('canUseAssistant', true)
            ->missing('ai')
            ->missing('assistant'));

        Http::assertNothingSent();
        Queue::assertNothingPushed();

        for ($request = 0; $request < 10; $request++) {
            $this->postJson(route('assistant.search'), ['query' => ''])->assertUnprocessable();
        }
        $this->postJson(route('assistant.search'), ['query' => ''])->assertTooManyRequests();
        $this->get(route('assistant.index'))->assertOk();
    }

    public function test_guest_cannot_open_page(): void
    {
        $this->get(route('assistant.index'))->assertRedirect(route('login'));
    }

    public function test_history_is_available_independently_of_rag_and_context_on_the_page(): void
    {
        config(['ai.assistant_history_enabled' => true, 'ai.assistant_rag_enabled' => false, 'ai.assistant_context_enabled' => true]);
        $this->actingAs(User::factory()->create())->get(route('assistant.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('historyEnabled', true)->where('dialogueEnabled', false)->where('contextEnabled', false));

        config(['ai.assistant_rag_enabled' => true]);
        $this->get(route('assistant.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('historyEnabled', true)->where('dialogueEnabled', true)->where('contextEnabled', true));
    }

    public function test_unverified_user_cannot_open_page_or_see_navigation_link(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('assistant.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->where('canUseAssistant', false));
    }

    public function test_flag_off_blocks_page_and_hides_navigation_link(): void
    {
        config(['ai.assistant_enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->get(route('assistant.index'))
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->where('canUseAssistant', false));
    }
}
