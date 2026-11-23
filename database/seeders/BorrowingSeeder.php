<?php

namespace Database\Seeders;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

class BorrowingSeeder extends Seeder
{
    /**
     * Crea préstamos de ejemplo en entornos de desarrollo y pruebas vinculados
     * a los equipos, perfiles y cuentas de administración sembrados.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'BorrowingSeeder solo puede ejecutarse en local o testing.'
            );
        }

        /** @var list<array{patrimonial_code: string, student_dni: string, registered_by_email: string, borrowing_date: Carbon, due_date: Carbon, return_date: ?Carbon, status: string, observations: ?string}> $borrowings */
        $borrowings = [
            [
                'patrimonial_code' => 'UCH-EQ-00002',
                'student_dni' => '70000005',
                'registered_by_email' => 'coordinador@example.com',
                'borrowing_date' => now()->subHours(2),
                'due_date' => now()->addHours(4),
                'return_date' => null,
                'status' => 'En curso',
                'observations' => 'Préstamo activo en sala de lectura.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00001',
                'student_dni' => '70000006',
                'registered_by_email' => 'ayudante@example.com',
                'borrowing_date' => now()->subDays(3),
                'due_date' => now()->subDays(3)->addHours(6),
                'return_date' => now()->subDays(3)->addHours(4),
                'status' => 'Devuelto',
                'observations' => 'Devuelto en óptimas condiciones.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00003',
                'student_dni' => '70000004',
                'registered_by_email' => 'admin@example.com',
                'borrowing_date' => now()->subDays(5),
                'due_date' => now()->subDays(3),
                'return_date' => null,
                'status' => 'Vencido',
                'observations' => 'Préstamo vencido pendiente de regularización.',
            ],
        ];

        foreach ($borrowings as $data) {
            $equipment = Equipment::query()->where('patrimonial_code', $data['patrimonial_code'])->first();
            $profile = Profile::query()->where('dni', $data['student_dni'])->first();

            if ($equipment === null || $profile === null) {
                continue;
            }

            $registeredBy = User::query()->where('email', $data['registered_by_email'])->first();

            $alreadyExists = Borrowing::query()
                ->where('equipment_id', $equipment->getKey())
                ->where('student_dni', $profile->dni)
                ->where('status', $data['status'])
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            Borrowing::query()->create([
                'equipment_id' => $equipment->getKey(),
                'profile_id' => $profile->getKey(),
                'student_dni' => $profile->dni,
                'borrowing_date' => $data['borrowing_date'],
                'due_date' => $data['due_date'],
                'return_date' => $data['return_date'],
                'status' => $data['status'],
                'observations' => $data['observations'],
                'registered_by' => $registeredBy?->getKey(),
            ]);
        }
    }
}
