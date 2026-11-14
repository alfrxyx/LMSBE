<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssignmentController extends Controller
{
    /**
     * [MAHASISWA] Mengunggah tugas video praktik aktivitas fisik.
     * Mendukung kebutuhan skripsi PJKR Universitas Malang.
     */
    public function store(Request $request)
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id',
            'title'      => 'required|string|max:255',
            'video'      => 'required|mimes:mp4,mov,avi|max:51200', // Batas naik ke 50MB untuk video praktik
        ]);

        // Simpan file ke storage/app/public/assignments
        $path = $request->file('video')->store('assignments', 'public');

        $assignment = Assignment::create([
            'course_id'  => $request->course_id,
            'user_id'    => Auth::id(), // Menggunakan Auth::id() lebih aman
            'title'      => $request->title,
            'video_path' => $path,
            'status'     => 'pending',
        ]);

        // NOTIFIKASI KE SEMUA DOSEN/ADMIN
        $dosens = User::whereIn('role', ['dosen', 'admin'])->get();
        foreach ($dosens as $dosen) {
            \App\Models\Notification::create([
                'user_id' => $dosen->id,
                'title' => 'Tugas Baru Masuk',
                'message' => Auth::user()->name . ' baru saja mengirim tugas video: ' . $request->title,
                'type' => 'info',
                'action_url' => '/dosen/grading'
            ]);
        }

        return response()->json([
            'message' => 'Tugas video berhasil diunggah! Menunggu penilaian dosen.',
            'data'    => $assignment
        ], 201);
    }

    /**
     * [DOSEN/ADMIN] Mengambil daftar tugas yang belum dinilai.
     * Digunakan untuk TeacherDashboard dinamis.
     */
    public function getPendingAssignments()
    {
        $pending = Assignment::with(['user', 'course'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $pending
        ]);
    }

    /**
     * [MAHASISWA] Melihat riwayat tugas sendiri.
     */
    public function myAssignments()
    {
        $assignments = Assignment::where('user_id', Auth::id())
            ->with('course')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $assignments
        ]);
    }

    /**
     * [DOSEN/ADMIN] Mengambil daftar pengiriman YouTube yang butuh dinilai.
     */
    public function getYoutubeSubmissions()
    {
        // Cari di user_progress yang punya assignment_link tapi belum is_completed atau butuh review
        $pending = \App\Models\UserProgress::with(['user', 'level'])
            ->whereNotNull('assignment_link')
            ->where('is_completed', false)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $pending
        ]);
    }

    /**
     * [DOSEN/ADMIN] Memberikan nilai dan feedback atau meminta revisi (Trigger Gamifikasi).
     */
    public function grade(Request $request, $id)
    {
        $request->validate([
            'earned_points' => 'required|integer|min:0',
            'feedback'      => 'nullable|string',
            'rubric_scores' => 'nullable|array',
            'status'        => 'nullable|in:approved,revision',
        ]);

        $isRevision = $request->input('status') === 'revision';
        $rubricScores = $request->input('rubric_scores');
        $rawFeedback = $request->input('feedback');

        // Ekstraksi nilai rubrik ke kolom JSON khusus jika ada format RUBRIC_DATA
        if (empty($rubricScores) && $rawFeedback && str_contains($rawFeedback, 'RUBRIC_DATA:')) {
            try {
                $parts = explode('|FEEDBACK:', $rawFeedback);
                $jsonStr = str_replace('RUBRIC_DATA:', '', $parts[0]);
                $rubricScores = json_decode($jsonStr, true);
            } catch (\Throwable $e) {}
        }

        return DB::transaction(function () use ($request, $id, $isRevision, $rubricScores) {
            // 1. Coba cari di user_progress (Sistem Link YouTube)
            $progress = \App\Models\UserProgress::find($id);
            
            if ($progress) {
                $oldPoints = $progress->is_completed ? (int) ($progress->earned_points ?? 0) : 0;
                $user = User::findOrFail($progress->user_id);

                if ($isRevision) {
                    $progress->update([
                        'is_completed'  => false,
                        'feedback'      => $request->feedback,
                        'rubric_scores' => $rubricScores,
                        'earned_points' => 0,
                        'completed_at'  => null,
                    ]);

                    $user->points = max(0, $user->points - $oldPoints);

                    \App\Models\Notification::create([
                        'user_id'    => $user->id,
                        'title'      => 'Tugas Video Perlu Revisi',
                        'message'    => 'Dosen meminta revisi pada tugas video Anda. Silakan baca catatan evaluasi dan kirim ulang tautan video perbaikan.',
                        'type'       => 'warning',
                        'action_url' => $progress->level ? '/courses/' . $progress->level->course_id : '/courses',
                    ]);
                } else {
                    $progress->update([
                        'is_completed'  => true,
                        'feedback'      => $request->feedback,
                        'rubric_scores' => $rubricScores,
                        'earned_points' => $request->earned_points,
                        'completed_at'  => now(),
                    ]);
                    
                    $user->points = max(0, $user->points + ($request->earned_points - $oldPoints));
                    
                    // NOTIFIKASI
                    \App\Models\Notification::create([
                        'user_id'    => $user->id,
                        'title'      => $oldPoints > 0 ? 'Nilai Tugas Diperbarui' : 'Tugas Telah Dinilai',
                        'message'    => 'Tugas video praktik Anda telah dinilai oleh dosen (Poin: +' . $request->earned_points . ' XP).',
                        'type'       => 'success',
                        'action_url' => $progress->level ? '/courses/' . $progress->level->course_id : '/courses',
                    ]);
                }
            } else {
                // 2. Jika tidak ada, coba cari di assignments (Sistem File Upload)
                $assignment = Assignment::find($id);
                if (!$assignment) {
                    return response()->json(['message' => 'Data tidak ditemukan'], 404);
                }

                $oldPoints = ($assignment->status === 'reviewed') ? (int) ($assignment->earned_points ?? 0) : 0;
                $user = User::findOrFail($assignment->user_id);

                if ($isRevision) {
                    $assignment->update([
                        'earned_points' => 0,
                        'feedback'      => $request->feedback,
                        'status'        => 'pending',
                    ]);
                    $user->points = max(0, $user->points - $oldPoints);
                } else {
                    $assignment->update([
                        'earned_points' => $request->earned_points,
                        'feedback'      => $request->feedback,
                        'status'        => 'reviewed',
                    ]);
                    $user->points = max(0, $user->points + ($request->earned_points - $oldPoints));
                }
            }

            // Update Level (Setiap 500 XP)
            $user->level = (int) floor($user->points / 500) + 1;
            $user->save();

            // Evaluasi otomatis seluruh Kriteria Lencana (Achievements/Badges) saat tugas lulus dinilai
            $unlockedBadges = [];
            if (!$isRevision) {
                $unlockedBadges = $user->evaluateAchievements();

                if (!empty($unlockedBadges)) {
                    \App\Models\Notification::create([
                        'user_id'    => $user->id,
                        'title'      => 'Lencana Baru Terbuka!',
                        'message'    => 'Selamat! Anda berhasil membuka lencana: ' . implode(', ', $unlockedBadges),
                        'type'       => 'success',
                        'action_url' => '/achievements',
                    ]);
                }
            }

            // Bersihkan cache leaderboard agar peringkat langsung ter-update secara real-time di semua kelas
            \App\Http\Controllers\LeaderboardController::clearCache($user->classroom_id);
            foreach ($user->classrooms()->pluck('classrooms.id') as $clsId) {
                \App\Http\Controllers\LeaderboardController::clearCache($clsId);
            }

            return response()->json([
                'status'          => 'success',
                'message'         => $isRevision ? 'Catatan revisi berhasil dikirim ke mahasiswa!' : 'Penilaian berhasil disimpan!',
                'new_level'       => $user->level,
                'unlocked_badges' => $unlockedBadges,
            ]);
        });
    }
}