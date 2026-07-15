<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Jalankan seluruh database seeders.
     */
    public function run(): void
    {
        $this->call([
            CourseSeeder::class,
            UserSeeder::class,
            AchievementSeeder::class, // Pastikan file ini ada
        ]);
    }
}