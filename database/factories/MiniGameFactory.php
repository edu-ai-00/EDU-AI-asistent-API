<?php

namespace Database\Factories;

use App\Models\MiniGame;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MiniGame>
 */
class MiniGameFactory extends Factory
{
    protected $model = MiniGame::class;

    /**
     * Define the model's default state: active, unlocked, unlimited plays.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'title' => fake()->words(2, true),
            'perex' => fake()->sentence(10),
            'description' => fake()->paragraph(),
            'image_url' => null,
            'sensor' => 'gyroscope',
            'duration_minutes' => fake()->numberBetween(2, 10),
            'min_level' => 1,
            'daily_attempts' => null,
            'cooldown_hours' => null,
            'setup' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the game is hidden from the listing.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
