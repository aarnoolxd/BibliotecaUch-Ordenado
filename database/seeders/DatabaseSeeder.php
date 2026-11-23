<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Llama al seeder de roles que son los del sistema
        $this->call(RoleSeeder::class);

        // Verifica si el entorno es local o testing y realiza los seeders correspondientes
        if (app()->environment('local', 'testing')) {
            $this->call([
                DevUsersSeeder::class,
                ProfileSeeder::class,
                EquipmentSeeder::class,
            ]);
        }
    }
}
