<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Tabel Pertanyaan (Questions)
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->onDelete('cascade'); // Terhubung ke Pertemuan
            $table->text('text'); // Teks pertanyaan
            $table->integer('points')->default(10); // Poin jika benar
            $table->timestamps();
        });

        // Tabel Pilihan Jawaban (Options)
        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->onDelete('cascade'); // Terhubung ke Pertanyaan
            $table->string('text'); // Teks pilihan (A/B/C/D)
            $table->boolean('is_correct')->default(false); // Apakah ini jawaban benar?
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('options');
        Schema::dropIfExists('questions');
    }
};
