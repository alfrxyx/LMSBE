<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'user_id',
        'title',
        'video_path',
        'earned_points',
        'feedback',
        'status',
    ];

    /**
     * Relasi balik ke Kursus.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Relasi balik ke Mahasiswa yang mengumpulkan.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}