<?php

namespace Database\Seeders;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

class BorrowingSeeder extends Seeder
{
    /**
     * Crea préstamos de ejemplo representativos de todos los flujos vigentes:
     * - Computadora En curso (+2h) con periférico adicional N:N
     * - Laptop En curso sin límite de tiempo (due_date = null)
     * - Préstamo de solo periféricos/otros sin equipo principal (equipment_id = null, due_date = null)
     * - Computadora Vencido (due_date expirada, lista para probar devolución vencida)
     * - Préstamo Devuelto sin incidencias
     * - Préstamo Devuelto con incidencia por daño real (equipment_incidents)
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'BorrowingSeeder solo puede ejecutarse en local o testing.'
            );
        }

        /**
         * @var list<array{
         *     primary_code: ?string,
         *     additional_codes: list<string>,
         *     student_dni: string,
         *     registered_by_email: string,
         *     borrowing_date: Carbon,
         *     due_date: ?Carbon,
         *     return_date: ?Carbon,
         *     status: string,
         *     observations: ?string,
         *     incidents: list<array{equipment_code: string, description: string, photo: ?string}>
         * }> $borrowings
         */
        $borrowings = [
            // 1. Computadora En curso (+2h) + Periférico adicional
            [
                'primary_code' => 'UCH-EQ-00002',
                'additional_codes' => ['UCH-EQ-00005'],
                'student_dni' => '70000005',
                'registered_by_email' => 'coordinador@example.com',
                'borrowing_date' => now()->subMinutes(45),
                'due_date' => now()->subMinutes(45)->addHours(2),
                'return_date' => null,
                'status' => Borrowing::STATUS_IN_PROGRESS,
                'observations' => 'Préstamo activo en sala de lectura con mouse inalámbrico.',
                'incidents' => [],
            ],
            // 2. Laptop En curso sin límite de tiempo
            [
                'primary_code' => 'UCH-EQ-00003',
                'additional_codes' => [],
                'student_dni' => '70000006',
                'registered_by_email' => 'ayudante@example.com',
                'borrowing_date' => now()->subHours(3),
                'due_date' => null,
                'return_date' => null,
                'status' => Borrowing::STATUS_IN_PROGRESS,
                'observations' => 'Entregada con maletín y cargador original.',
                'incidents' => [],
            ],
            // 3. Solo Periféricos / Otros En curso (sin equipo principal ni límite)
            [
                'primary_code' => null,
                'additional_codes' => ['UCH-EQ-00009', 'UCH-EQ-00010'],
                'student_dni' => '70000007',
                'registered_by_email' => 'ayudante@example.com',
                'borrowing_date' => now()->subHour(),
                'due_date' => null,
                'return_date' => null,
                'status' => Borrowing::STATUS_IN_PROGRESS,
                'observations' => 'Estudiante utiliza su propia laptop; solicita teclado y adaptador HDMI.',
                'incidents' => [],
            ],
            // 4. Computadora Vencido (plazo de 2h expirado)
            [
                'primary_code' => 'UCH-EQ-00011',
                'additional_codes' => [],
                'student_dni' => '70000004',
                'registered_by_email' => 'admin@example.com',
                'borrowing_date' => now()->subHours(4),
                'due_date' => now()->subHours(2),
                'return_date' => null,
                'status' => Borrowing::STATUS_OVERDUE,
                'observations' => 'Préstamo de computadora vencido pendiente de devolución.',
                'incidents' => [],
            ],
            // 5. Computadora Devuelto sin incidencias
            [
                'primary_code' => 'UCH-EQ-00001',
                'additional_codes' => [],
                'student_dni' => '70000006',
                'registered_by_email' => 'ayudante@example.com',
                'borrowing_date' => now()->subDays(2),
                'due_date' => now()->subDays(2)->addHours(2),
                'return_date' => now()->subDays(2)->addMinutes(95),
                'status' => Borrowing::STATUS_RETURNED,
                'observations' => 'Devuelto en óptimas condiciones.',
                'incidents' => [],
            ],
            // 6. Laptop Devuelto con incidencia por daño real (equipo en Mantenimiento)
            [
                'primary_code' => 'UCH-EQ-00004',
                'additional_codes' => ['UCH-EQ-00014'],
                'student_dni' => '70000008',
                'registered_by_email' => 'coordinador@example.com',
                'borrowing_date' => now()->subDays(3),
                'due_date' => null,
                'return_date' => now()->subDays(2),
                'status' => Borrowing::STATUS_RETURNED,
                'observations' => 'Préstamo docente para exposición en auditorio.',
                'incidents' => [
                    [
                        'equipment_code' => 'UCH-EQ-00004',
                        'description' => 'Bisagra derecha floja con holgura al abrir la pantalla.',
                        'photo' => null,
                    ],
                ],
            ],
        ];

        foreach ($borrowings as $data) {
            $profile = Profile::query()->where('dni', $data['student_dni'])->first();

            if ($profile === null) {
                continue;
            }

            $primaryEquipment = $data['primary_code'] !== null
                ? Equipment::query()->where('patrimonial_code', $data['primary_code'])->first()
                : null;

            if ($data['primary_code'] !== null && $primaryEquipment === null) {
                continue;
            }

            $additionalEquipments = $data['additional_codes'] !== []
                ? Equipment::query()->whereIn('patrimonial_code', $data['additional_codes'])->get()
                : collect();

            if ($primaryEquipment === null && $additionalEquipments->isEmpty()) {
                continue;
            }

            $registeredBy = User::query()->where('email', $data['registered_by_email'])->first();

            $alreadyExists = Borrowing::query()
                ->when(
                    $primaryEquipment !== null,
                    fn ($query) => $query->where('equipment_id', $primaryEquipment->getKey()),
                    fn ($query) => $query->whereNull('equipment_id'),
                )
                ->where('student_dni', $profile->dni)
                ->where('status', $data['status'])
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $borrowing = Borrowing::query()->create([
                'equipment_id' => $primaryEquipment?->getKey(),
                'profile_id' => $profile->getKey(),
                'student_dni' => $profile->dni,
                'borrowing_date' => $data['borrowing_date'],
                'due_date' => $data['due_date'],
                'return_date' => $data['return_date'],
                'status' => $data['status'],
                'observations' => $data['observations'],
                'registered_by' => $registeredBy?->getKey(),
            ]);

            if ($additionalEquipments->isNotEmpty()) {
                $borrowing->additionalEquipment()->sync(
                    $additionalEquipments->pluck('id')->all(),
                );
            }

            foreach ($data['incidents'] as $incidentData) {
                $affectedEquipment = Equipment::query()
                    ->where('patrimonial_code', $incidentData['equipment_code'])
                    ->first();

                if ($affectedEquipment === null) {
                    continue;
                }

                EquipmentIncident::query()->create([
                    'equipment_id' => $affectedEquipment->getKey(),
                    'borrowing_id' => $borrowing->getKey(),
                    'description' => $incidentData['description'],
                    'photo' => $incidentData['photo'],
                ]);
            }
        }
    }
}
