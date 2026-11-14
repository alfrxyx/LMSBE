<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_progress', function (Blueprint $table) {
            if (!Schema::hasColumn('user_progress', 'rubric_scores')) {
                $table->json('rubric_scores')->nullable()->after('feedback');
            }
            $table->index(['user_id', 'level_id']);
            $table->index(['level_id', 'is_completed']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'points', 'level']);
        });
    }

    public function down(): void
    {
        Schema::table('user_progress', function (Blueprint $table) {
            if (Schema::hasColumn('user_progress', 'rubric_scores')) {
                $table->dropColumn('rubric_scores');
            }
            $table->dropIndex(['user_id', 'level_id']);
            $table->dropIndex(['level_id', 'is_completed']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'points', 'level']);
        });
    }
};

