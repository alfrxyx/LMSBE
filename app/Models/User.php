<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
     * Memperbarui streak login harian mahasiswa.
     */
    public function updateStreak()
    {
        $today = now()->startOfDay();
        $lastActivity = $this->last_activity_date ? \Carbon\Carbon::parse($this->last_activity_date)->startOfDay() : null;

        if (!$lastActivity) {
            // Pengguna baru pertama kali aktif
            $this->current_streak = 1;
        } else {
            $diffInDays = $today->diffInDays($lastActivity);

            if ($diffInDays == 1) {
                // Login hari berikutnya secara berturut-turut
                $this->current_streak += 1;
            } elseif ($diffInDays > 1) {
                // Terputus (Terakhir login lebih dari sehari yang lalu)
                $this->current_streak = 1;
            }
            // Jika diffInDays == 0 (Hari yang sama), tidak perlu update streak
        }

        // Simpan tanggal aktivitas hari ini agar tidak bertambah terus di hari yang sama
        $this->last_activity_date = $today;
        $this->save();
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
}