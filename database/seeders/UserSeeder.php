<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Classroom;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $course = \App\Models\Course::first();

        // 1. Buat Kelas Default
        $classroom = Classroom::create([
            'name' => 'Offering A',
            'code' => 'PJKR-DEF6',
            'course_id' => $course ? $course->id : null,
            'semester' => $course ? $course->semester : 6,
            'is_active' => true,
        ]);

        // 2. Akun Admin
        User::create([
            'nim' => '0000000000',
            'name' => 'Admin Gamify',
            'email' => 'admin@mail.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // 3. Akun Dosen PJKR UM
        User::create([
            'nim' => '1111111111',
            'name' => 'Dosen PJKR UM',
            'semester' => '-',
            'phone' => '08123456789',
            'email' => 'dosen@mail.com',
            'password' => Hash::make('password'),
            'role' => 'dosen',
        ]);

        // 4. Akun Mahasiswa (Alfarabi Gazali Sati)
        $student = User::create([
            'nim' => '2106116092', 
            'name' => 'Alfarabi Gazali Sati',
            'semester' => '6',
            'phone' => '08951234567',
            'email' => 'alfa@mail.com',
            'password' => Hash::make('password'),
            'role' => 'student',
            'points' => 250,
            'level' => 1,
            'classroom_id' => $classroom->id,
        ]);

        $student->classrooms()->attach($classroom->id);
    }
}