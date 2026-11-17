<?php

namespace Database\Seeders;

use App\Models\User;
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
        // Local development only. Both passwords are "password". Each starts with $10 of credit.
        User::factory()->withCredits(10)->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        User::factory()->admin()->withCredits(10)->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);
    }
}
