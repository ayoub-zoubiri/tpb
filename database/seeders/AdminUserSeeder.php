<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@toplago.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password123'), // Secure password in production
                'role' => 'admin',
            ]
        );
    }
}
