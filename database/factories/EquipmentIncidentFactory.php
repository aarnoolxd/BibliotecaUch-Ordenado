<?php

namespace Database\Factories;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentIncident>
 */
class EquipmentIncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'borrowing_id' => Borrowing::factory(),
            'equipment_id' => function (array $attributes): int {
                $borrowing = Borrowing::query()
                    ->with('additionalEquipment')
                    ->find($attributes['borrowing_id'] ?? null);

                if ($borrowing !== null) {
                    if ($borrowing->equipment_id !== null) {
                        return (int) $borrowing->equipment_id;
                    }

                    $additionalId = $borrowing->additionalEquipment->first()?->getKey();

                    if ($additionalId !== null) {
                        return (int) $additionalId;
                    }
                }

                return (int) Equipment::factory()->create()->getKey();
            },
            'description' => fake()->sentence(10),
            'photo' => null,
        ];
    }

    /**
     * Indica que la incidencia incluye fotografía de evidencia.
     */
    public function withPhoto(string $path = 'borrowings/incidents/damage.jpg'): static
    {
        return $this->state(fn (array $attributes): array => [
            'photo' => $path,
        ]);
    }
}
