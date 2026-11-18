<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DevUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'admin'],
            ['name' => 'Super Admin', 'email' => 'superadmin@example.com', 'role' => 'super-admin'],
            ['name' => 'Coordinador', 'email' => 'coordinador@example.com', 'role' => 'coordinador'],
            ['name' => 'Ayudante', 'email' => 'ayudante@example.com', 'role' => 'ayudante'],
        ];


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

