<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProfileSeeder extends Seeder
{
    /**
     * Crea perfiles de ejemplo vinculados a las cuentas de administración
     * y perfiles de estudiantes y docentes sin cuenta vinculada, usando
     * todos los campos vigentes (apellido materno, tipo de usuario, programa y ciclo).
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'ProfileSeeder solo puede ejecutarse en local o testing.'
            );
        }

        /**
         * @var list<array{
         *     email: ?string,
         *     dni: string,
         *     first_name: string,
         *     last_name: string,
         *     maternal_last_name: string,
         *     user_type: string,
         *     program: ?string,
         *     cycle: ?int,
         *     phone: string,
         *     address: ?string
         * }> $profiles
         */
        $profiles = [
            [
                'email' => 'superadmin@example.com',
                'dni' => '70000001',
                'first_name' => 'Carlos Eduardo',
                'last_name' => 'Mendoza',
                'maternal_last_name' => 'Quispe',
                'user_type' => Profile::USER_TYPE_ADMINISTRATIVE,
                'program' => null,
                'cycle' => null,
                'phone' => '987654321',
                'address' => 'Av. Universitaria 5175, Los Olivos',
            ],
            [
                'email' => 'admin@example.com',
                'dni' => '70000002',
                'first_name' => 'Maria Elena',
                'last_name' => 'Rojas',
                'maternal_last_name' => 'Paredes',
                'user_type' => Profile::USER_TYPE_ADMINISTRATIVE,
                'program' => null,
                'cycle' => null,
                'phone' => '987654322',
                'address' => 'Av. Alfredo Mendiola 3698, Independencia',
            ],
            [
                'email' => 'coordinador@example.com',
                'dni' => '70000003',
                'first_name' => 'Luis Fernando',
                'last_name' => 'Torres',
                'maternal_last_name' => 'Villanueva',
                'user_type' => Profile::USER_TYPE_ADMINISTRATIVE,
                'program' => null,
                'cycle' => null,
                'phone' => '987654323',
                'address' => 'Av. Carlos Izaguirre 845, Los Olivos',
            ],
            [
                'email' => 'ayudante@example.com',
                'dni' => '70000004',
                'first_name' => 'Ana Sofia',
                'last_name' => 'Chavez',
                'maternal_last_name' => 'Morales',
                'user_type' => Profile::USER_TYPE_STUDENT,
                'program' => 'Ingeniería de Sistemas e Informática',
                'cycle' => 7,
                'phone' => '987654324',
                'address' => 'Jr. Las Palmeras 412, Los Olivos',
            ],
            [
                'email' => null,
                'dni' => '70000005',
                'first_name' => 'Diego Alejandro',
                'last_name' => 'Salazar',
                'maternal_last_name' => 'Huaman',
                'user_type' => Profile::USER_TYPE_STUDENT,
                'program' => 'Ingeniería de Sistemas e Informática',
                'cycle' => 5,
                'phone' => '987654325',
                'address' => 'Av. Tupac Amaru 2140, Comas',
            ],
            [
                'email' => null,
                'dni' => '70000006',
                'first_name' => 'Valeria Lucia',
                'last_name' => 'Gomez',
                'maternal_last_name' => 'Castillo',
                'user_type' => Profile::USER_TYPE_STUDENT,
                'program' => 'Administración',
                'cycle' => 3,
                'phone' => '987654326',
                'address' => 'Av. Antunez de Mayolo 1120, Los Olivos',
            ],
            [
                'email' => null,
                'dni' => '70000007',
                'first_name' => 'Mateo Gabriel',
                'last_name' => 'Diestra',
                'maternal_last_name' => 'Espinoza',
                'user_type' => Profile::USER_TYPE_STUDENT,
                'program' => 'Contabilidad',
                'cycle' => 8,
                'phone' => '987654327',
                'address' => null,
            ],
            [
                'email' => null,
                'dni' => '70000008',
                'first_name' => 'Ricardo Javier',
                'last_name' => 'Lozano',
                'maternal_last_name' => 'Rivas',
                'user_type' => Profile::USER_TYPE_TEACHER,
                'program' => null,
                'cycle' => null,
                'phone' => '987654328',
                'address' => 'Jr. Huancavelica 320, San Martín de Porres',
            ],
        ];

        foreach ($profiles as $data) {
            $user = $data['email'] !== null
                ? User::query()->where('email', $data['email'])->first()
                : null;

            $profile = Profile::query()->firstOrCreate(
                ['student_code' => $data['dni']],
                [
                    'user_id' => ($user !== null && ! $user->profile()->exists()) ? $user->getKey() : null,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'maternal_last_name' => $data['maternal_last_name'],
                    'user_type' => $data['user_type'],
                    'program' => $data['program'],
                    'cycle' => $data['cycle'],
                    'phone' => $data['phone'],
                    'address' => $data['address'],
                    'photo' => null,
                ],
            );

            $updates = [];

            if ($user !== null && $profile->user_id === null && ! $user->profile()->exists()) {
                $updates['user_id'] = $user->getKey();
            }

            if ($profile->maternal_last_name === null) {
                $updates['last_name'] = $data['last_name'];
                $updates['maternal_last_name'] = $data['maternal_last_name'];
            }

            if ($profile->user_type === null) {
                $updates['user_type'] = $data['user_type'];
                $updates['program'] = $data['program'];
                $updates['cycle'] = $data['cycle'];
            }

            if ($profile->phone === null) {
                $updates['phone'] = $data['phone'];
            }

            if ($updates !== []) {
                $profile->update($updates);
            }
        }
    }
}
