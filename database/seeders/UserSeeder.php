<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Akun Admin
        User::create([
            'nim' => '0000000000',
            'name' => 'Admin Gamify',
            'email' => 'admin@mail.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // 2. Akun Dosen PJKR UM
        User::create([
            'nim' => '1111111111',
            'name' => 'Dosen PJKR UM',
            'semester' => '-',
            'phone' => '08123456789',
            'email' => 'dosen@mail.com',
            'password' => Hash::make('password'),
            'role' => 'dosen',
        ]);

        // 3. Akun Mahasiswa (Alfarabi Gazali Sati)
        User::create([
            'nim' => '2106116092', 
            'name' => 'Alfarabi Gazali Sati',
            'semester' => '6',
            'phone' => '08951234567',
            'email' => 'alfa@mail.com',
            'password' => Hash::make('password'),
            'role' => 'student',
            'points' => 250,
            'level' => 1,
        ]);
    }
}