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
            'equipment_id' => Equipment::factory(),
            'borrowing_id' => Borrowing::factory(),
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
