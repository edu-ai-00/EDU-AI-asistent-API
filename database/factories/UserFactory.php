<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // An account a test conjures up is an established one: it has a
            // nickname and a history, so it is past the sign-up onboarding.
            // Use notOnboarded() for the account that has never been opened.
            'onboarded_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * An account that has never been through the sign-up onboarding
     * (BR-YXVMKY) — a student the teacher has just provisioned, say.
     */
    public function notOnboarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'onboarded_at' => null,
        ]);
    }

    /**
     * Enrol the user in one or more classrooms (BR-3V2P22). Membership is a
     * pivot row, so it cannot be a plain attribute in create([...]).
     *
     * @param  \App\Models\Classroom|int|array<int, \App\Models\Classroom|int>  $classrooms
     */
    public function inClassroom(\App\Models\Classroom|int|array $classrooms): static
    {
        $ids = collect(is_array($classrooms) ? $classrooms : [$classrooms])
            ->map(fn ($c) => $c instanceof \App\Models\Classroom ? $c->id : (int) $c)
            ->all();

        return $this->afterCreating(function (\App\Models\User $user) use ($ids) {
            $user->classrooms()->syncWithoutDetaching($ids);
        });
    }
}
