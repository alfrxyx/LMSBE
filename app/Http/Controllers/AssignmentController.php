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
     * [DOSEN/ADMIN] Memberikan nilai dan feedback (Trigger Gamifikasi).
     */
    public function grade(Request $request, $id)
    {
        $request->validate([
            'earned_points' => 'required|integer|min:0',
            'feedback'      => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $id) {
            // 1. Coba cari di user_progress (Sistem Link YouTube)
            $progress = \App\Models\UserProgress::find($id);
            
            if ($progress) {
                $progress->update([
                    'is_completed' => true,
                    'feedback' => $request->feedback,
                    'earned_points' => $request->earned_points,
                    'completed_at' => now(),
                ]);
                
                $user = User::findOrFail($progress->user_id);
                $user->points += $request->earned_points;
                
                // NOTIFIKASI
                \App\Models\Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Tugas Telah Dinilai',
                    'message' => 'Tugas Anda pada materi telah dinilai oleh dosen.',
                    'type' => 'success',
                ]);
            } else {
                // 2. Jika tidak ada, coba cari di assignments (Sistem File Upload)
                $assignment = Assignment::find($id);
                if (!$assignment) {
                    return response()->json(['message' => 'Data tidak ditemukan'], 404);
                }

                $assignment->update([
                    'earned_points' => $request->earned_points,
                    'feedback'      => $request->feedback,
                    'status'        => 'reviewed',
                ]);
                
                $user = User::findOrFail($assignment->user_id);
                $user->points += $request->earned_points;
            }

            // Update Level (Setiap 500 XP)
            $user->level = floor($user->points / 500) + 1;
            $user->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Penilaian berhasil disimpan!',
                'new_level' => $user->level
            ]);
        });
    }
}