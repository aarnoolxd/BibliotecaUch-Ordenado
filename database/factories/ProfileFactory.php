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
            'student_code' => fake()->unique()->numerify('########'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'maternal_last_name' => fake()->lastName(),
            'user_type' => Profile::USER_TYPE_STUDENT,
            'program' => fake()->randomElement(array_keys(Profile::PROGRAMS)),
            'cycle' => fake()->numberBetween(1, 10),
            'phone' => fake()->numerify('9########'),
            'address' => fake()->streetAddress(),
            'photo' => null,
        ];
    }

    /**
     * Indica que el perfil pertenece a un docente (sin programa ni ciclo).
     */
    public function teacher(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_type' => Profile::USER_TYPE_TEACHER,
            'program' => null,
            'cycle' => null,
        ]);
    }

    /**
     * Indica que el perfil pertenece a personal administrativo (sin programa ni ciclo).
     */
    public function administrative(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_type' => Profile::USER_TYPE_ADMINISTRATIVE,
            'program' => null,
            'cycle' => null,
        ]);
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
