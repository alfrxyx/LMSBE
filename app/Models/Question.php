<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    protected $fillable = ['level_id', 'text', 'points'];

    /**
     * Relasi: Satu pertanyaan punya banyak pilihan jawaban.
     */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class);
    }

    /**
     * Relasi: Satu pertanyaan milik satu pertemuan (level).
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }
}
