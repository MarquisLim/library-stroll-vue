<?php

namespace Database\Factories;

use App\Models\AssistantConversation;
use App\Models\AssistantTurn;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssistantTurn> */
class AssistantTurnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => AssistantConversation::factory(),
            'sequence' => 1,
            'user_query' => fake()->sentence(),
            'result_status' => 'no_results',
            'requested_limit' => 10,
            'source_references' => [],
        ];
    }
}
