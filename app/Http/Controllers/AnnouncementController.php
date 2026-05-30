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
            'type' => 'required|in:info,warning,success'
        ]);

        $announcement = Announcement::create([
            'user_id' => Auth::id(),
            'title' => $request->title,
            'message' => $request->message,
            'type' => $request->type
        ]);

        // NOTIFIKASI KE SEMUA MAHASISWA
        $students = \App\Models\User::where('role', 'student')->get();
        foreach ($students as $student) {
            \App\Models\Notification::create([
                'user_id' => $student->id,
                'title' => 'Pengumuman Baru: ' . $request->title,
                'message' => 'Dosen telah memposting pengumuman baru. Silakan cek dashboard Anda.',
                'type' => $request->type,
                'action_url' => '/dashboard'
            ]);
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
