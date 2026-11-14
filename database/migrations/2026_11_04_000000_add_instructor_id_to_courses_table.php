<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan dosen pengampu pada mata kuliah agar notifikasi tugas
     * hanya dikirim ke dosen yang bersangkutan.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('courses', 'instructor_id')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->unsignedBigInteger('instructor_id')->nullable()->after('is_active');
                $table->index('instructor_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('courses', 'instructor_id')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->dropIndex(['instructor_id']);
                $table->dropColumn('instructor_id');
            });
        }
    }
};

