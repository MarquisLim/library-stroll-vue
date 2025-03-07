<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CollectionFactory extends Factory
{
    protected $model = Collection::class;

    public function definition()
    {
        return [
            'name' => $this->faker->word, // Генерируем случайное название
            'user_id' => User::factory(), // Создаём пользователя, если не передан
        ];
    }
}
