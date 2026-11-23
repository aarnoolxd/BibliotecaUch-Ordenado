<?php

namespace Database\Factories;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Borrowing>
 */
class BorrowingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'equipment_id' => Equipment::factory(),
            'profile_id' => Profile::factory(),
            'student_dni' => fn (array $attributes): string => Profile::query()->find($attributes['profile_id'])?->dni
                ?? fake()->numerify('########'),
            'borrowing_date' => now(),
            'due_date' => now()->addDay(),
            'return_date' => null,
            'status' => 'En curso',
            'observations' => null,
            'registered_by' => User::factory(),
        ];
    }

    /**
     * Marca el préstamo como devuelto con fecha de devolución registrada.
     */
    public function returned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'Devuelto',
            'return_date' => now(),
        ]);
    }

    /**
     * Marca el préstamo como vencido con fecha límite pasada.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'Vencido',
            'borrowing_date' => now()->subDays(3),
            'due_date' => now()->subDay(),
            'return_date' => null,
        ]);
    }
}
