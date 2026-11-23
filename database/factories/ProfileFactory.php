<?php

namespace Database\Factories;

use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'dni' => fake()->unique()->numerify('########'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->numerify('9########'),
            'address' => fake()->streetAddress(),
            'photo' => null,
        ];
    }

    /**
     * Indica que el perfil cuenta con una fotografía registrada.
     */
    public function withPhoto(string $path = 'profiles/photos/avatar.jpg'): static
    {
        return $this->state(fn (array $attributes): array => [
            'photo' => $path,
        ]);
    }
}
