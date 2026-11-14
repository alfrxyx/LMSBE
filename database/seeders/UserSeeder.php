<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Daftarkan Akun Resmi Produksi (Bapak Dosen & Admin Pengembang).
     * Seluruh data dummy mahasiswa dan kelas lama dibersihkan.
     */
    public function run(): void
    {
        // 1. Akun Admin Pengembang (Alfarabi Gazali Sati)
        User::create([
            'nim' => '2200018100',
            'name' => 'Alfarabi Gazali Sati (Admin)',
            'email' => 'alfarabigajali@gmail.com',
            'phone' => '08951234567',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // 2. Akun Dosen PJKR Universitas Negeri Malang (Bapak Rajip Mustafillah Rusdiyanto)
        User::create([
            'nim' => '198912112024061001',
            'name' => 'Rajip Mustafillah Rusdiyanto',
            'email' => 'rajip.mustafillah.fik@um.ac.id',
            'phone' => '+6285222317755',
            'password' => Hash::make('!Rusdiyanto1'),
            'role' => 'dosen',
            'semester' => '-',
        ]);
    }
}
