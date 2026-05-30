<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Level extends Model
{
    // Kolom yang boleh diisi
    protected $fillable = [
        'course_id', 
        'title', 
        'description', 
        'pdf_path', 
        'youtube_id', 
        'xp_reward', 
        'activity_type', 
        'order'
    ];

    // Relasi balik ke Mata Kuliah
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Relasi ke pertanyaan kuis (Quiz Builder)
     */
    public function questions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Relasi ke progres mahasiswa (UserProgress)
     */
    public function progress(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserProgress::class);
    }
}
