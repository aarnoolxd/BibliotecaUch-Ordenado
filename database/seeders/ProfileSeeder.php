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
     * y perfiles sin cuenta vinculada para entornos de desarrollo y pruebas.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'ProfileSeeder solo puede ejecutarse en local o testing.'
            );
        }

        /** @var list<array{email: ?string, dni: string, first_name: string, last_name: string, phone: ?string, address: ?string}> $profiles */
        $profiles = [
            [
                'email' => 'superadmin@example.com',
                'dni' => '70000001',
                'first_name' => 'Carlos Eduardo',
                'last_name' => 'Mendoza Quispe',
                'phone' => '987654321',
                'address' => 'Av. Universitaria 5175, Los Olivos',
            ],
            [
                'email' => 'admin@example.com',
                'dni' => '70000002',
                'first_name' => 'Maria Elena',
                'last_name' => 'Rojas Paredes',
                'phone' => '987654322',
                'address' => 'Av. Alfredo Mendiola 3698, Independencia',
            ],
            [
                'email' => 'coordinador@example.com',
                'dni' => '70000003',
                'first_name' => 'Luis Fernando',
                'last_name' => 'Torres Villanueva',
                'phone' => '987654323',
                'address' => 'Av. Carlos Izaguirre 845, Los Olivos',
            ],
            [
                'email' => 'ayudante@example.com',
                'dni' => '70000004',
                'first_name' => 'Ana Sofia',
                'last_name' => 'Chavez Morales',
                'phone' => '987654324',
                'address' => 'Jr. Las Palmeras 412, Los Olivos',
            ],
            [
                'email' => null,
                'dni' => '70000005',
                'first_name' => 'Diego Alejandro',
                'last_name' => 'Salazar Huaman',
                'phone' => '987654325',
                'address' => 'Av. Tupac Amaru 2140, Comas',
            ],
            [
                'email' => null,
                'dni' => '70000006',
                'first_name' => 'Valeria Lucia',
                'last_name' => 'Gomez Castillo',
                'phone' => null,
                'address' => null,
            ],
        ];

        foreach ($profiles as $data) {
            $user = $data['email'] !== null
                ? User::query()->where('email', $data['email'])->first()
                : null;

            if ($user !== null && $user->profile()->exists()) {
                continue;
            }

            $profile = Profile::query()->firstOrCreate(
                ['dni' => $data['dni']],
                [
                    'user_id' => $user?->getKey(),
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'phone' => $data['phone'],
                    'address' => $data['address'],
                    'photo' => null,
                ],
            );

            if ($user !== null && $profile->user_id === null) {
                $profile->update(['user_id' => $user->getKey()]);
            }
        }
    }
}
