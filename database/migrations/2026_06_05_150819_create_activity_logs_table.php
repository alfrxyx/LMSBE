<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('user_id')->constrained()->onDelete('cascade');
            $blueprint->string('action'); // e.g., 'login', 'submit_quiz', 'complete_level'
            $blueprint->string('model_type')->nullable(); // e.g., 'App\Models\Level'
            $blueprint->unsignedBigInteger('model_id')->nullable();
            $blueprint->json('payload')->nullable(); // Detail tambahan
            $blueprint->string('ip_address')->nullable();
            $blueprint->string('user_agent')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
