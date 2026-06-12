<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customers = [
            [
                'name' => 'Ahmad Rahman',
                'email' => 'ahmad.rahman@example.com',
                'phone' => '+6281234567890',
                'password' => Hash::make('password'),
                'role' => 'member',
                'points_balance' => 150,
                'created_at' => now()->subDays(45),
            ],
            [
                'name' => 'Siti Nurhaliza',
                'email' => 'siti.nurhaliza@example.com',
                'phone' => '+6281234567891',
                'password' => Hash::make('password'),
                'role' => 'member',
                'points_balance' => 200,
                'created_at' => now()->subDays(30),
            ],
            [
                'name' => 'Budi Santoso',
                'email' => 'budi.santoso@example.com',
                'phone' => '+6281234567892',
                'password' => Hash::make('password'),
                'role' => 'member',
                'points_balance' => 75,
                'created_at' => now()->subDays(60),
            ],
            [
                'name' => 'Maya Sari',
                'email' => 'maya.sari@example.com',
                'phone' => '+6281234567893',
                'password' => Hash::make('password'),
                'role' => 'member',
                'points_balance' => 300,
                'created_at' => now()->subDays(15),
            ],
            [
                'name' => 'Rizki Pratama',
                'email' => 'rizki.pratama@example.com',
                'phone' => '+6281234567894',
                'password' => Hash::make('password'),
                'role' => 'member',
                'points_balance' => 50,
                'created_at' => now()->subDays(90),
            ],
            [
                'name' => 'Dewi Lestari',
                'email' => 'dewi.lestari@example.com',
                'phone' => '+6281234567895',
                'password' => Hash::make('password'),
                'role' => 'member',
                'points_balance' => 0,
                'created_at' => now()->subDays(120),
            ],
            [
                'name' => 'Agus Wijaya',
                'email' => 'agus.wijaya@example.com',
                'phone' => '+6281234567896',
                'password' => Hash::make('password'),
                'role' => 'member',
                'points_balance' => 125,
                'created_at' => now()->subDays(20),
            ],
            [
                'name' => 'Nina Amelia',
                'email' => 'nina.amelia@example.com',
                'phone' => '+6281234567897',
                'password' => Hash::make('password'),
                'role' => 'member',
                'points_balance' => 180,
                'created_at' => now()->subDays(10),
            ],
            [
                'name' => 'Fajar Nugroho',
                'email' => 'fajar.nugroho@example.com',
                'phone' => '+6281234567898',
                'password' => Hash::make('password'),
                'role' => 'member',
                'points_balance' => 0,
                'created_at' => now()->subDays(150),
            ],
            [
                'name' => 'Lina Marlina',
                'email' => 'lina.marlina@example.com',
                'phone' => '+6281234567899',
                'password' => Hash::make('password'),
                'role' => 'member',
                'points_balance' => 95,
                'created_at' => now()->subDays(25),
            ],
        ];

        foreach ($customers as $customer) {
            User::query()->updateOrCreate(
                ['email' => $customer['email']],
                $customer
            );
        }
    }
}
