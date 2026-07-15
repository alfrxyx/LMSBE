<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Course;

class CourseSeeder extends Seeder
{
    /**
     * Jalankan database seeds untuk Mata Kuliah.
     * Dikosongkan agar Admin/Dosen bisa mengisi materi secara manual dari awal.
     */
    public function run(): void
    {
        Course::create([
            'title' => 'Metodologi Penelitian PJKR',
            'description' => 'Mata kuliah wajib semester 6 untuk memahami dasar-dasar penelitian olahraga.',
            'semester' => 6,
            'total_points' => 1000,
            'order' => 1,
            'is_active' => true,
        ]);

        Course::create([
            'title' => 'Evaluasi Pembelajaran PJKR',
            'description' => 'Mata kuliah yang membahas teknik evaluasi, penilaian, dan pengukuran hasil belajar olahraga.',
            'semester' => 6,
            'total_points' => 1000,
            'order' => 2,
            'is_active' => true,
        ]);
    }
}
