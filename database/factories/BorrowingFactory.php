<?php

namespace Database\Factories;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
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
            'equipment_id' => Equipment::factory()->ofType('Computadora'),
            'profile_id' => Profile::factory(),
            'student_code' => fn (array $attributes): string => Profile::query()->find($attributes['profile_id'])?->student_code
                ?? fake()->numerify('########'),
            'borrowing_date' => now(),
            'due_date' => function (array $attributes) {
                $equipmentId = $attributes['equipment_id'] ?? null;

                if ($equipmentId === null) {
                    return null;
                }

                $equipment = Equipment::query()->find($equipmentId);

                if ($equipment === null) {
                    return null;
                }

                $borrowingDate = $attributes['borrowing_date'] ?? now();

                return Borrowing::calculateDueDate($equipment->type, Carbon::parse($borrowingDate));
            },
            'return_date' => null,
            'status' => Borrowing::STATUS_IN_PROGRESS,
            'observations' => null,
            'registered_by' => User::factory(),
        ];
    }

    /**
     * Indica un préstamo sin equipo principal (para prestar únicamente periféricos/otros).
     */
    public function withoutPrimaryEquipment(): static
    {
        return $this->state(fn (array $attributes): array => [
            'equipment_id' => null,
            'due_date' => null,
        ]);
    }

    /**
     * Adjunta dispositivos adicionales (Periférico/Otro) al préstamo tras crearlo.
     */
    public function withAdditionalEquipment(Equipment ...$items): static
    {
        return $this->afterCreating(function (Borrowing $borrowing) use ($items): void {
            $borrowing->additionalEquipment()->syncWithoutDetaching(
                collect($items)->map(fn (Equipment $item): int => (int) $item->getKey())->all(),
            );
        });
    }

    /**
     * Marca el préstamo como devuelto con fecha de devolución registrada.
     */
    public function returned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Borrowing::STATUS_RETURNED,
            'return_date' => now(),
        ]);
    }

    /**
     * Marca el préstamo como vencido con fecha límite pasada.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Borrowing::STATUS_OVERDUE,
            'borrowing_date' => now()->subHours(5),
            'due_date' => now()->subHours(3),
            'return_date' => null,
        ]);
    }
}
