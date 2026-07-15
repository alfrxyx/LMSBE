<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    /**
     * Atribut yang dapat diisi secara massal (Mass Assignable).
     * Sesuai kebutuhan input Dosen PJKR UM.
     */
    protected $fillable = [
        'title',        // Ganti istilah Course -> Mata Kuliah
        'description',  // Deskripsi Mata Kuliah
        'semester',     // Semester Mata Kuliah (Target Mahasiswa)
        'thumbnail',    // Gambar cover
        'total_points', // Total XP yang bisa didapat
        'order',        // Urutan antar Mata Kuliah
        'is_active',    // Status aktif/non-aktif
    ];

    /**
     * Casting tipe data agar konsisten saat diterima oleh Frontend React.js.
     * PENTING: Cast 'levels' dihapus karena sekarang menggunakan relasi tabel terpisah.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'total_points' => 'integer',
        'order' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * Relasi ke Pertemuan (Levels) di dalam Mata Kuliah ini.
     * Sangat penting untuk fitur Progresi Terkunci (Sequential Access).
     */
    public function levels(): HasMany
    {
        // Mengurutkan pertemuan berdasarkan kolom 'order' secara otomatis
        return $this->hasMany(Level::class)->orderBy('order', 'asc');
    }

    /**
     * Relasi ke daftar tugas (Pengumpulan Link YouTube) mahasiswa.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * Relasi ke Kelas (Classrooms) yang terafiliasi dengan Mata Kuliah ini.
     */
    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }
}