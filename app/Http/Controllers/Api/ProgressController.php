<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Level;
use App\Models\UserProgress;
use App\Models\UserQuizAnswer;
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

        // Cek Jadwal Akses Pembukaan (open_at)
        if ($level->open_at && now()->lessThan($level->open_at)) {
            return response()->json([
                'can_access' => false,
                'message' => 'Materi ini belum dibuka. Baru dapat diakses pada ' . $level->open_at->format('d M Y H:i') . ' WIB.'
            ], 403);
        }

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

        // Cek progres level sebelumnya (boleh lanjut jika sudah dinilai ATAU jika tipe tugas & link sudah dikirim)
        $isPrevCompleted = UserProgress::where('user_id', $user->id)
                                    ->where('level_id', $previousLevel->id)
                                    ->where(function($q) use ($previousLevel) {
                                        $q->where('is_completed', true);
                                        if ($previousLevel->activity_type === 'assignment') {
                                            $q->orWhereNotNull('assignment_link');
                                        }
                                    })
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

        // Cek Jadwal Akses Pembukaan (open_at)
        if ($level->open_at && now()->lessThan($level->open_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Materi ini belum dibuka. Baru dapat diakses pada ' . $level->open_at->format('d M Y H:i') . ' WIB.'
            ], 403);
        }

        // Cek Batas Akhir Akses (Untuk Kuis yang ditutup ketat)
        if ($level->activity_type === 'quiz' && $level->deadline && now()->greaterThan($level->deadline)) {
            return response()->json([
                'success' => false,
                'message' => 'Kuis ini telah ditutup pada ' . $level->deadline->format('d M Y H:i') . ' WIB.'
            ], 403);
        }

        // Cek Batasan Waktu Durasi Kuis (Duration Limit)
        if ($level->activity_type === 'quiz' && $level->duration_minutes) {
            $progress = UserProgress::where('user_id', $user->id)->where('level_id', $level->id)->first();
            if ($progress) {
                $maxDurationSeconds = ($level->duration_minutes * 60) + 60; // 60 detik toleransi network delay
                if (now()->diffInSeconds($progress->created_at) > $maxDurationSeconds) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Waktu pengerjaan kuis Anda telah habis (melebihi batas ' . $level->duration_minutes . ' menit).'
                    ], 403);
                }
            }
        }

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
                                        ->where(function($q) use ($previousLevel) {
                                            $q->where('is_completed', true);
                                            if ($previousLevel->activity_type === 'assignment') {
                                                $q->orWhereNotNull('assignment_link');
                                            }
                                        })
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

                $baseXP = $level->xp_reward;
                $isLate = false;
                $penaltyAmount = 0;

                // LOGIKA PENALTI: Cek Deadline
                if ($level->deadline && now()->greaterThan($level->deadline)) {
                    $isLate = true;
                }

                $assignmentLink = 'Completed via ' . $level->activity_type;
                $extraData = [
                    'is_late' => $isLate
                ];

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
                        $isCorrect = false;

                        if ($submittedOptionId && $correctOption && $submittedOptionId == $correctOption->id) {
                            $correctCount++;
                            $isCorrect = true;
                        }

                        // SIMPAN RIWAYAT JAWABAN (Point 1)
                        UserQuizAnswer::updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'level_id' => $level->id,
                                'question_id' => $question->id
                            ],
                            [
                                'option_id' => $submittedOptionId,
                                'is_correct' => $isCorrect
                            ]
                        );
                    }

                    $score = round(($correctCount / $totalQuestions) * 100);
                    $extraData['score'] = $score;

                    // XP dinamis berdasarkan persentase skor kuis
                    $xpGained = (int) round(($score / 100) * $baseXP);

                    if ($isLate) {
                        $penaltyAmount = (int) round($xpGained * 0.2); // Penalti 20%
                        $xpGained = $xpGained - $penaltyAmount;
                    }

                    $extraData['penalty_amount'] = $penaltyAmount;
                    $extraData['base_xp'] = $xpGained + $penaltyAmount;

                    $assignmentLink = 'Quiz Score: ' . $score . '%';
                } else {
                    $xpGained = $baseXP;
                    if ($isLate) {
                        $penaltyAmount = (int) round($baseXP * 0.2);
                        $xpGained = $baseXP - $penaltyAmount;
                    }
                    $extraData['penalty_amount'] = $penaltyAmount;
                    $extraData['base_xp'] = $baseXP;

                    if ($level->activity_type === 'assignment') {
                        $assignmentLink = $request->assignment_link;
                    }
                }

                // Simpan progres mahasiswa (tugas praktik di-set false agar dosen yang melakukan penilaian)
                $isCompleted = ($level->activity_type !== 'assignment');

                UserProgress::updateOrCreate(
                    ['user_id' => $user->id, 'level_id' => $level->id],
                    [
                        'assignment_link' => $assignmentLink,
                        'is_completed' => $isCompleted
                    ]
                );

                // Tambahkan XP instan hanya jika levelnya bukan tugas praktik (karena tugas praktik dinilai manual oleh dosen)
                if ($awardXP && $isCompleted) {
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

    /**
     * 3. AMBIL RIWAYAT KUIS
     * Mengambil jawaban mahasiswa pada pertemuan tertentu untuk di-review.
     */
    public function getQuizHistory($level_id)
    {
        $user = Auth::user();
        $answers = UserQuizAnswer::where('user_id', $user->id)
            ->where('level_id', $level_id)
            ->with(['question.options'])
            ->get();

        if ($answers->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Belum ada riwayat kuis untuk pertemuan ini.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $answers
        ]);
    }

    /**
     * 4. INISIALISASI MULAI KUIS (MEREKAM WAKTU MULAI & HITUNG SISA DETIK)
     */
    public function startQuiz($level_id)
    {
        $user = Auth::user();
        $level = Level::findOrFail($level_id);

        if ($level->activity_type !== 'quiz') {
            return response()->json(['message' => 'Level ini bukan tipe kuis'], 400);
        }

        // Cek Jadwal Buka
        if ($level->open_at && now()->lessThan($level->open_at)) {
            return response()->json([
                'message' => 'Kuis ini belum dibuka. Baru dapat diakses pada ' . $level->open_at->format('d M Y H:i') . ' WIB.'
            ], 403);
        }

        // Cek Jadwal Tutup
        if ($level->deadline && now()->greaterThan($level->deadline)) {
            return response()->json([
                'message' => 'Kuis ini telah ditutup pada ' . $level->deadline->format('d M Y H:i') . ' WIB.'
            ], 403);
        }

        // Cari progress
        $progress = UserProgress::where('user_id', $user->id)
            ->where('level_id', $level->id)
            ->first();

        // Jika kuis sudah selesai dikerjakan, larang akses mulai kembali
        if ($progress && $progress->is_completed) {
            return response()->json([
                'message' => 'Anda sudah mengerjakan kuis ini dan tidak dapat mengulangnya kembali.'
            ], 403);
        }

        if (!$progress) {
            $progress = UserProgress::create([
                'user_id' => $user->id,
                'level_id' => $level->id,
                'is_completed' => false
            ]);
        }

        // Hitung sisa waktu pengerjaan (dalam detik)
        $durationSeconds = $level->duration_minutes ? ((int) $level->duration_minutes * 60) : null;
        $elapsedSeconds = (int) now()->diffInSeconds($progress->created_at);
        
        $remainingSeconds = $durationSeconds ? max(0, $durationSeconds - $elapsedSeconds) : null;

        // Jika kuis ditutup serentak, cek sisa waktu sampai deadline
        if ($level->deadline) {
            $secondsToDeadline = (int) now()->diffInSeconds($level->deadline, false);
            if ($secondsToDeadline < 0) {
                $remainingSeconds = 0;
            } elseif ($remainingSeconds === null || $secondsToDeadline < $remainingSeconds) {
                $remainingSeconds = max(0, $secondsToDeadline);
            }
        }

        return response()->json([
            'status' => 'success',
            'start_time' => $progress->created_at,
            'duration_minutes' => $level->duration_minutes,
            'remaining_seconds' => $remainingSeconds !== null ? (int) $remainingSeconds : null
        ]);
    }
}