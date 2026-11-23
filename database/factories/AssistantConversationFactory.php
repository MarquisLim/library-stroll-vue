<?php

namespace Database\Factories;

use App\Models\AssistantConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssistantConversation> */
class AssistantConversationFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()];
    }
}
