<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up() {
    Schema::create('levels', function (Blueprint $table) {
        $table->id();
        $table->foreignId('course_id')->constrained()->onDelete('cascade');
        $table->string('title'); // Contoh: "Pertemuan 1 - Senam"
        $table->text('description'); // Deskripsi/Instruksi Dosen
        $table->string('pdf_path')->nullable(); // Path file PDF
        $table->string('youtube_id')->nullable(); // ID Video YouTube Dosen
        $table->integer('xp_reward')->default(100); // XP yang didapat
        $table->enum('activity_type', ['checklist', 'quiz', 'assignment', 'video']); // Jenis penutup
        $table->integer('order'); // Urutan pertemuan (1, 2, 3...)
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};
