<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Achievement;

class AchievementSeeder extends Seeder
{
    public function run(): void
    {
        $achievements = [
            [
                'name' => 'Rookie',
                'description' => 'Selamat datang! Kamu telah memulai perjalanan akademik di PJKR UM.',
                'badge_icon' => 'rookie-badge.png',
                'required_points' => 0,
            ],
            [
                'name' => 'Aktivitas Terpuji',
                'description' => 'Mengunggah video praktik aktivitas fisik pertama.',
                'badge_icon' => 'activity-badge.png',
                'required_points' => 100,
            ],
            [
                'name' => 'Master Basket',
                'description' => 'Menyelesaikan semua level di kursus Teknik Dasar Bola Basket.',
                'badge_icon' => 'basket-master.png',
                'required_points' => 500,
            ],
            [
                'name' => 'Konsistensi Tinggi',
                'description' => 'Mempertahankan login harian selama 7 hari berturut-turut.',
                'badge_icon' => 'streak-badge.png',
                'required_points' => 0, 
            ],
            [
                'name' => 'Nilai Sempurna',
                'description' => 'Berhasil mendapatkan skor 100 pada kuis yang dikerjakan.',
                'badge_icon' => 'perfect-score.png',
                'required_points' => 0,
            ],
            [
                'name' => 'Ranking 1 Leaderboard',
                'description' => 'Berhasil menduduki peringkat pertama pada daftar mahasiswa berprestasi.',
                'badge_icon' => 'top-rank.png',
                'required_points' => 0,
            ],
            [
                'name' => 'Kolektor Lencana',
                'description' => 'Mahasiswa teladan yang telah mengumpulkan 7 lencana berbeda.',
                'badge_icon' => 'badge-collector.png',
                'required_points' => 0,
            ],
        ];

        foreach ($achievements as $achievement) {
            Achievement::updateOrCreate(['name' => $achievement['name']], $achievement);
        }
    }
}