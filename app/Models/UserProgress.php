<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProgress extends Model
{
    use HasFactory;

    /**
     * Nama tabel yang didefinisikan di database.
     */
    protected $table = 'user_progress';

    /**
     * Atribut yang dapat diisi secara massal (Mass Assignable).
     * assignment_link digunakan untuk menyimpan URL YouTube mahasiswa.
     */
    protected $fillable = [
        'user_id',
        'level_id',
        'assignment_link',
        'is_completed',
        'completed_at',
        'feedback',
        'earned_points',
    ];

    /**
     * Casting tipe data agar konsisten saat dikirim ke React.js.
     * is_completed akan otomatis menjadi true/false (boolean).
     */
    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'earned_points' => 'integer',
    ];

    /**
     * Relasi balik ke Mahasiswa (User).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relasi balik ke detail Pertemuan (Level).
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }
}