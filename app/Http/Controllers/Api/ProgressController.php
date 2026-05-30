<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Level;
use App\Models\UserProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProgressController extends Controller
{
    /**
     * 1. FITUR CEK AKSES (SEQUENTIAL ACCESS)
     * Mahasiswa hanya bisa buka pertemuan jika pertemuan sebelumnya sudah 'is_completed'.
     */
    public function checkAccess($level_id)
    {
        $user = Auth::user();
        $level = Level::findOrFail($level_id);

        // Cari urutan level secara keseluruhan
        $levels = Level::where('course_id', $level->course_id)
                       ->orderBy('order', 'asc')
                       ->orderBy('id', 'asc')
                       ->get();
        
        $currentIndex = $levels->search(function($l) use ($level) {
            return $l->id === $level->id;
        });

        if ($currentIndex === 0) {
            return response()->json([
                'can_access' => true,
                'message' => 'Akses diizinkan (Pertemuan Pertama)'
            ], 200);
        }

        // Cari level sebelumnya dalam sequence
        $previousLevel = $levels[$currentIndex - 1];

        // Cek progres level sebelumnya
        $isPrevCompleted = UserProgress::where('user_id', $user->id)
                                    ->where('level_id', $previousLevel->id)
                                    ->where('is_completed', true)
                                    ->exists();

        if (!$isPrevCompleted) {
            return response()->json([
                'can_access' => false,
                'message' => 'Selesaikan "' . $previousLevel->title . '" terlebih dahulu.'
            ], 403);
        }

        return response()->json([
            'can_access' => true,
            'message' => 'Akses diizinkan'
        ], 200);
    }

    /**
     * 2. FITUR SUBMIT AKTIVITAS (SIMPAN TUGAS & XP)
     * Memproses penyelesaian materi: Checklist, Kuis, atau Link YouTube.
     */
    public function submitActivity(Request $request, $level_id)
    {
        $user = Auth::user();
        $level = Level::findOrFail($level_id);

        // LOGIKA ROADMAP: CEK URUTAN SEBELUM SUBMIT (Strict Completion)
        // Cari semua level di course ini, urutkan, dan temukan posisi level saat ini
        $levels = Level::where('course_id', $level->course_id)
                       ->orderBy('order', 'asc')
                       ->orderBy('id', 'asc')
                       ->get();
        
        $currentIndex = $levels->search(function($l) use ($level) {
            return $l->id === $level->id;
        });

        if ($currentIndex > 0) {
            $previousLevel = $levels[$currentIndex - 1];
            
            $isPrevCompleted = UserProgress::where('user_id', $user->id)
                                        ->where('level_id', $previousLevel->id)
                                        ->where('is_completed', true)
                                        ->exists();
            
            if (!$isPrevCompleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selesaikan "' . $previousLevel->title . '" terlebih dahulu sebelum menyelesaikan materi ini.'
                ], 403);
            }
        }

        // Validasi berdasarkan tipe aktivitas
        if ($level->activity_type === 'assignment') {
            $request->validate([
                'assignment_link' => ['required', 'url', 'regex:/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/.+$/']
            ], [
                'assignment_link.regex' => 'Hanya menerima tautan YouTube yang valid.'
            ]);
        } elseif ($level->activity_type === 'quiz') {
            $request->validate([
                'answers' => 'required|array',
            ]);
        }

        // Gunakan Transaction agar data XP dan Progres tetap sinkron
        return DB::transaction(function () use ($user, $level, $request) {
            try {
                // CEK APAKAH SUDAH PERNAH DISELESAIKAN (Cegah Spam XP)
                $alreadyCompleted = UserProgress::where('user_id', $user->id)
                                                ->where('level_id', $level->id)
                                                ->where('is_completed', true)
                                                ->exists();
                
                // Jangan throw error 400, cukup set flag agar tidak tambah poin dua kali
                $awardXP = !$alreadyCompleted;

                $xpGained = $level->xp_reward;
                $assignmentLink = 'Completed via ' . $level->activity_type;
                $extraData = [];

                // LOGIKA KHUSUS KUIS
                if ($level->activity_type === 'quiz') {
                    $questions = $level->questions()->with('options')->get();
                    $correctCount = 0;
                    $totalQuestions = $questions->count();

                    if ($totalQuestions === 0) {
                        return response()->json(['message' => 'Kuis ini belum memiliki pertanyaan'], 400);
                    }

                    foreach ($questions as $question) {
                        $submittedOptionId = $request->answers[$question->id] ?? null;
                        $correctOption = $question->options->where('is_correct', true)->first();

                        if ($submittedOptionId && $correctOption && $submittedOptionId == $correctOption->id) {
                            $correctCount++;
                        }
                    }

                    $score = round(($correctCount / $totalQuestions) * 100);
                    $extraData['score'] = $score;

                    if ($score < 70) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Skor Anda ' . $score . '. Minimal 70 untuk lulus.',
                            'score' => $score
                        ], 422);
                    }

                    $assignmentLink = 'Quiz Score: ' . $score . '%';
                } elseif ($level->activity_type === 'assignment') {
                    $assignmentLink = $request->assignment_link;
                }

                // Simpan progres mahasiswa
                UserProgress::updateOrCreate(
                    ['user_id' => $user->id, 'level_id' => $level->id],
                    [
                        'assignment_link' => $assignmentLink,
                        'is_completed' => true
                    ]
                );

                if ($awardXP) {
                    // Tambahkan XP ke profil mahasiswa
                    $user->points += $xpGained;
                    $user->level = floor($user->points / 500) + 1;
                    $user->save();

                    // CEK ACHIEVEMENT: Nilai Sempurna
                    if ($level->activity_type === 'quiz' && isset($score) && $score === 100) {
                        $perfectAchievement = \App\Models\Achievement::where('name', 'Nilai Sempurna')->first();
                        if ($perfectAchievement && !$user->achievements()->where('achievement_id', $perfectAchievement->id)->exists()) {
                            $user->achievements()->attach($perfectAchievement->id, ['earned_at' => now()]);
                        }
                    }

                    // PEMBERIAN LENCANA BERDASARKAN POIN (Legacy Logic)
                    $availableAchievements = \App\Models\Achievement::where('required_points', '>', 0)
                        ->where('required_points', '<=', $user->points)
                        ->get();
                    foreach ($availableAchievements as $achievement) {
                        $alreadyHas = DB::table('user_achievements')
                            ->where('user_id', $user->id)
                            ->where('achievement_id', $achievement->id)
                            ->exists();
                        
                        if (!$alreadyHas) {
                            $user->achievements()->attach($achievement->id, ['earned_at' => now()]);
                        }
                    }

                    // CEK ACHIEVEMENT: Kolektor Lencana (Mendapatkan 7 lencana)
                    if ($user->achievements()->count() >= 7) {
                        $collectorAchievement = \App\Models\Achievement::where('name', 'Kolektor Lencana')->first();
                        if ($collectorAchievement && !$user->achievements()->where('achievement_id', $collectorAchievement->id)->exists()) {
                            $user->achievements()->attach($collectorAchievement->id, ['earned_at' => now()]);
                        }
                    }
                }

                return response()->json(array_merge([
                    'success' => true,
                    'message' => $awardXP ? 'Selamat! Anda mendapatkan +' . $xpGained . ' XP' : 'Progres berhasil diperbarui.',
                    'xp_gained' => $awardXP ? $xpGained : 0,
                    'new_xp' => $user->points,
                    'new_level' => $user->level
                ], $extraData));

            } catch (\Exception $e) {
                Log::error("Error submitActivity: " . $e->getMessage());
                return response()->json(['message' => 'Gagal menyimpan progres: ' . $e->getMessage()], 500);
            }
        });
    }
}