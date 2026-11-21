<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DevUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Verifica si el entorno es local o testing si no es asi lanza una excepcion
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'DevUsersSeeder solo puede ejecutarse en local o testing.'
            );
        }

        $users = [
            ['name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'admin'],
            ['name' => 'Super Admin', 'email' => 'superadmin@example.com', 'role' => 'super-admin'],
            ['name' => 'Coordinador', 'email' => 'coordinador@example.com', 'role' => 'coordinador'],
            ['name' => 'Ayudante', 'email' => 'ayudante@example.com', 'role' => 'ayudante'],
        ];

        // Crea los usuarios y asigna el rol correspondiente
        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),

                ]
            );
            $user->assignRole($data['role']);
        }
    }
}
