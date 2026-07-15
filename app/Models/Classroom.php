<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'semester',
        'is_active',
        'course_id',
    ];

    /**
     * Relasi ke Mata Kuliah (Course) spesifik kelas ini.
     */
    public function course(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    /**
     * Relasi ke Mahasiswa (User) yang terdaftar di kelas ini (Banyak-ke-Banyak).
     */
    public function students(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classroom_user');
    }
}
