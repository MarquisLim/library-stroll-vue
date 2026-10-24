<?php

namespace Database\Factories;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArtworkFactory extends Factory
{
    protected $model = Artwork::class;

    public function definition(): array
    {
        return [
            'user_id'        => User::factory(),
            'title'          => $this->faker->sentence(3),
            'description'    => $this->faker->paragraph(),
            'type'           => 'image',
            'is_published'   => true,
            'is_private'     => false,
            'is_adult'       => false,
            'has_ai'         => false,
            'allow_download' => true,
            'allow_comments' => true,
            'views_count'    => $this->faker->numberBetween(0, 500),
            'published_at'   => $this->faker->dateTimeBetween('-6 months'),
        ];
    }

    public function draft(): static
    {
        return $this->state([
            'is_published' => false,
            'published_at' => null,
            'views_count'  => 0,
        ]);
    }

    public function private(): static
    {
        return $this->state([
            'is_private' => true,
        ]);
    }

    public function adult(): static
    {
        return $this->state([
            'is_adult' => true,
        ]);
    }

    public function published(): static
    {
        return $this->state([
            'is_published' => true,
            'is_private'   => false,
            'published_at' => $this->faker->dateTimeBetween('-6 months'),
        ]);
    }
}
