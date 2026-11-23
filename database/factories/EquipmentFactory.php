<?php

namespace Database\Factories;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patrimonial_code' => 'UCH-EQ-'.fake()->unique()->numerify('#####'),
            'type' => fake()->randomElement(['Computadora', 'Laptop', 'Periférico', 'Otro']),
            'brand' => fake()->randomElement(['HP', 'Dell', 'Lenovo', 'Logitech']),
            'model' => fake()->bothify('Model-###??'),
            'serial_number' => 'SN-'.fake()->unique()->numerify('##########'),
            'status' => 'Disponible',
            'observations' => null,
        ];
    }

    /**
     * Define el estado operativo del equipo.
     */
    public function withStatus(string $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    /**
     * Define la categoría o tipo del equipo.
     */
    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }
}
