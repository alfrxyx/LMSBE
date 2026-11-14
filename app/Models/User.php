<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Atribut yang dapat diisi secara massal.
     * NIM Mahasiswa PJKR UM digunakan sebagai identitas utama.
     */
    protected $fillable = [
        'nim',
        'name',
        'semester',
        'phone',
        'email',
        'bio',
        'password',
        'role',
        'points',
        'level',
        'avatar',
        'current_streak',
        'last_activity_date',
        'classroom_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Casting data agar React.js menerima tipe data yang konsisten.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'points' => 'integer',
            'level' => 'integer',
            'current_streak' => 'integer',
            'last_activity_date' => 'date',
        ];
    }

    /**
     * Memperbarui streak login harian mahasiswa & evaluasi lencana streak.
     */
    public function updateStreak()
    {
        $today = now()->startOfDay();
        $lastActivity = $this->last_activity_date
            ? \Carbon\Carbon::parse($this->last_activity_date)->startOfDay()
            : null;

        if (!$lastActivity) {
            // Pengguna baru pertama kali aktif
            $this->current_streak = 1;
        } else {
            $diffInDays = (int) round(abs($today->diffInDays($lastActivity)));

            if ($diffInDays === 1) {
                // Login hari berikutnya secara berturut-turut -> Streak bertambah +1
                $this->current_streak += 1;
            } elseif ($diffInDays > 1) {
                // Terputus (Absen / tidak login 1 hari atau lebih) -> Wajib reset kembali ke 1
                $this->current_streak = 1;
            } elseif ($diffInDays === 0) {
                // Login berulang di hari yang sama -> Pertahankan streak saat ini (minimal 1)
                $this->current_streak = max(1, (int) $this->current_streak);
            }
        }

        $this->last_activity_date = $today;
        $this->level = (int) floor(($this->points ?? 0) / 500) + 1;
        $this->save();

        if ($this->role === 'student') {
            $this->evaluateAchievements();
        }
    }

    /**
     * Evaluasi terpusat seluruh syarat Lencana (Achievements) untuk mahasiswa.
     * Mengembalikan array nama lencana yang baru saja terbuka.
     */
    public function evaluateAchievements(array $context = []): array
    {
        if ($this->role !== 'student') {
            return [];
        }

        $unlockedNames = [];
        $allAchievements = Achievement::all()->keyBy('name');
        $existingIds = DB::table('user_achievements')
            ->where('user_id', $this->id)
            ->pluck('achievement_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $grant = function (?Achievement $ach) use (&$existingIds, &$unlockedNames) {
            if (!$ach) {
                return;
            }
            if (!in_array((int) $ach->id, $existingIds, true)) {
                $this->achievements()->attach($ach->id, ['earned_at' => now()]);
                $existingIds[] = (int) $ach->id;
                $unlockedNames[] = $ach->name;
            }
        };

        // 1. Rookie: Otomatis diberikan kepada setiap mahasiswa terdaftar
        $grant($allAchievements->get('Rookie'));

        // 2. Berdasarkan Required Points (misal: Aktivitas Terpuji >= 100 XP, Master Basket >= 500 XP)
        foreach ($allAchievements as $ach) {
            if ((int) $ach->required_points > 0 && (int) $this->points >= (int) $ach->required_points) {
                $grant($ach);
            }
        }

        // 3. Aktivitas Terpuji: Bisa juga terbuka saat sudah mengunggah video praktik pertama
        $hasVideoSubmission = $this->progress()
            ->whereNotNull('assignment_link')
            ->where(function ($q) {
                $q->where('assignment_link', 'LIKE', '%youtube.com%')
                  ->orWhere('assignment_link', 'LIKE', '%youtu.be%');
            })
            ->exists();
        if ($hasVideoSubmission) {
            $grant($allAchievements->get('Aktivitas Terpuji'));
        }

        // 4. Konsistensi Tinggi: Login streak >= 7 hari berturut-turut
        if ((int) $this->current_streak >= 7) {
            $grant($allAchievements->get('Konsistensi Tinggi'));
        }

        // 5. Nilai Sempurna: Skor kuis 100%
        if (!empty($context['quiz_score']) && (int) $context['quiz_score'] === 100) {
            $grant($allAchievements->get('Nilai Sempurna'));
        } else {
            $hasPerfectQuiz = $this->progress()
                ->where('assignment_link', 'Quiz Score: 100%')
                ->exists();
            if ($hasPerfectQuiz) {
                $grant($allAchievements->get('Nilai Sempurna'));
            }
        }

        // 6. Ranking 1 Leaderboard: Peringkat 1 di angkatan semesternya (minimal punya > 0 XP)
        if ((int) $this->points > 0) {
            $topStudentQuery = self::where('role', 'student');
            if ($this->semester) {
                $topStudentQuery->where('semester', $this->semester);
            }
            $maxPoints = (int) $topStudentQuery->max('points');

            // Mahasiswa dianggap di puncak jika poinnya setara dengan poin tertinggi di kelas/semesternya
            if ($maxPoints > 0 && (int) $this->points >= $maxPoints) {
                $grant($allAchievements->get('Ranking 1 Leaderboard'));
            }
        }

        // 7. Kolektor Lencana: Terbuka jika sudah mengumpulkan minimal 6 lencana lainnya
        $collectorAch = $allAchievements->get('Kolektor Lencana');
        if ($collectorAch) {
            $otherEarnedCount = count(array_filter(
                $existingIds,
                fn ($id) => $id !== (int) $collectorAch->id
            ));
            if ($otherEarnedCount >= 6) {
                $grant($collectorAch);
            }
        }

        return $unlockedNames;
    }

    /**
     * Relasi Utama ke Tabel Progres (PENTING untuk fitur kirim link YouTube).
     */
    public function progress(): HasMany
    {
        return $this->hasMany(UserProgress::class, 'user_id');
    }

    /**
     * Alias untuk Dashboard: Menampilkan riwayat tugas terbaru mahasiswa.
     * Menggunakan UserProgress karena kita menyimpan link YouTube di sana.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserProgress::class, 'user_id');
    }

    /**
     * Relasi ke Koleksi Lencana (Achievements) mahasiswa.
     */
    public function achievements(): BelongsToMany
    {
        return $this->belongsToMany(Achievement::class, 'user_achievements')
                    ->withPivot('earned_at');
    }

    /**
     * Relasi ke Kelas (Classroom) Aktif.
     */
    public function classroom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    /**
     * Relasi Banyak-ke-Banyak ke seluruh Kelas yang diikuti.
     */
    public function classrooms(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'classroom_user');
    }
}
