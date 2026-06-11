<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Level;
use App\Models\Course;
use App\Models\UserProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LevelCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_access_level_without_completing_previous_one()
    {
        $user = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'Metodologi Penelitian', 
            'semester' => '6',
            'description' => 'Deskripsi MK'
        ]);
        
        $level1 = Level::create([
            'course_id' => $course->id,
            'title' => 'Pertemuan 1',
            'description' => 'Materi 1',
            'order' => 1,
            'activity_type' => 'video',
            'xp_reward' => 100
        ]);

        $level2 = Level::create([
            'course_id' => $course->id,
            'title' => 'Pertemuan 2',
            'description' => 'Materi 2',
            'order' => 2,
            'activity_type' => 'video',
            'xp_reward' => 100
        ]);

        $this->actingAs($user, 'sanctum');

        // Coba cek akses level 2
        $response = $this->getJson("/api/check-access/{$level2->id}");

        $response->assertStatus(403);
    }

    public function test_student_gets_xp_after_completing_level()
    {
        $user = User::factory()->create(['role' => 'student', 'points' => 0]);
        $course = Course::create([
            'title' => 'Metodologi Penelitian', 
            'semester' => '6',
            'description' => 'Deskripsi MK'
        ]);
        
        $level1 = Level::create([
            'course_id' => $course->id,
            'title' => 'Pertemuan 1',
            'description' => 'Materi 1',
            'order' => 1,
            'activity_type' => 'video',
            'xp_reward' => 100
        ]);

        $this->actingAs($user, 'sanctum');

        $response = $this->postJson("/api/levels/{$level1->id}/complete");

        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals(100, $user->points);
    }
}
