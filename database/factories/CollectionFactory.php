<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CollectionFactory extends Factory
{
    protected $model = Collection::class;

    public function definition(): array
    {
        return [
            'user_id'    => User::factory(),
            'name'       => $this->faker->words(2, true),
            'is_private' => false,
        ];
    }

    public function private(): static
    {
        return $this->state([
            'is_private' => true,
        ]);
    }
}
