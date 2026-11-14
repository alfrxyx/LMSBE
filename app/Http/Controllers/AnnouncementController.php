<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnnouncementController extends Controller
{
    /**
     * Mengambil daftar pengumuman terbaru (Untuk Mahasiswa & Dosen)
     */
    public function index()
    {
        $announcements = Announcement::with('user:id,name,role')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $announcements
        ]);
    }

    /**
     * Dosen/Admin memposting pengumuman baru
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:info,warning,success',
            'course_id' => 'nullable|integer',
            'classroom_id' => 'nullable|integer',
            'semester' => 'nullable',
        ]);

        $announcement = Announcement::create([
            'user_id' => Auth::id(),
            'title' => $request->title,
            'message' => $request->message,
            'type' => $request->type
        ]);

        // Filter penerima notifikasi agar tidak spam ke seluruh mahasiswa se-kampus
        $studentsQuery = \App\Models\User::where('role', 'student');

        if ($request->filled('classroom_id')) {
            $studentsQuery->whereHas('classrooms', function ($q) use ($request) {
                $q->where('classrooms.id', $request->classroom_id);
            });
        } elseif ($request->filled('course_id')) {
            $studentsQuery->whereHas('classrooms', function ($q) use ($request) {
                $q->where('course_id', $request->course_id);
            });
        } elseif ($request->filled('semester')) {
            $studentsQuery->where('semester', $request->semester);
        }

        $students = $studentsQuery->get();
        $now = now();
        $actionUrl = $request->filled('course_id') ? '/courses/' . $request->course_id : '/dashboard';
        $notifData = [];

        foreach ($students as $student) {
            $notifData[] = [
                'user_id' => $student->id,
                'title' => 'Pengumuman: ' . $request->title,
                'message' => $request->message,
                'type' => $request->type,
                'action_url' => $actionUrl,
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bulk insert efisien tanpa memblokir server
        if (!empty($notifData)) {
            foreach (array_chunk($notifData, 100) as $chunk) {
                \App\Models\Notification::insert($chunk);
            }
        }

        return response()->json([
            'message' => 'Pengumuman berhasil diposting',
            'data' => $announcement
        ], 201);
    }

    /**
     * Menghapus pengumuman
     */
    public function destroy($id)
    {
        $announcement = Announcement::findOrFail($id);
        
        // Hanya pembuat atau admin yang boleh menghapus
        if ($announcement->user_id !== Auth::id() && Auth::user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $announcement->delete();
        return response()->json(['message' => 'Pengumuman dihapus']);
    }
}
