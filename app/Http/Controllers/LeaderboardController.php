<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LeaderboardController extends Controller
{
    /**
     * Menampilkan daftar peringkat mahasiswa berdasarkan poin tertinggi.
     * Mendukung filter: all (semua waktu), weekly (mingguan), monthly (bulanan).
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $filter = $request->query('filter', 'all');
        $semesterParam = $request->query('semester', 'all');
        
        $query = User::where('role', 'student');

        // KEBIJAKAN PRIVASI & FILTER LEADERBOARD:
        // 1. Jika user adalah student, paksa filter ke semester miliknya agar melihat teman seangkatan.
        // 2. Jika user adalah admin/dosen, mereka bisa memfilter ke semester manapun (atau 'all').
        if ($user && $user->role === 'student') {
            $semester = (string) $user->semester;
        } else {
            $semester = $semesterParam;
        }

        // Terapkan filter semester jika tidak 'all'
        if ($semester !== 'all') {
            $query->where('semester', $semester);
        }
        
        if ($filter === 'all') {
            // Semua waktu: Urutkan berdasarkan total poin akumulasi di tabel users
            $topStudents = $query->orderBy('points', 'desc')
                ->orderBy('level', 'desc')
                ->take(10)
                ->get(['id', 'name', 'points', 'level', 'avatar', 'nim', 'semester', 'bio']);
        } else {
            // Filter Mingguan/Bulanan: Hitung poin dari aktivitas di periode tersebut
            $startDate = $filter === 'weekly' ? Carbon::now()->startOfWeek() : Carbon::now()->startOfMonth();
            
            $topStudents = $query->select('id', 'name', 'level', 'avatar', 'nim', 'semester', 'bio')
                ->selectRaw("(
                    COALESCE((
                        SELECT SUM(l.xp_reward) 
                        FROM user_progress up 
                        JOIN levels l ON up.level_id = l.id 
                        WHERE up.user_id = users.id 
                          AND up.is_completed = 1 
                          AND up.updated_at >= ?
                    ), 0)
                    +
                    COALESCE((
                        SELECT SUM(a.earned_points) 
                        FROM assignments a 
                        WHERE a.user_id = users.id 
                          AND a.status = 'reviewed' 
                          AND a.updated_at >= ?
                    ), 0)
                ) as points", [$startDate, $startDate])
                ->orderBy('points', 'desc')
                ->orderBy('level', 'desc')
                ->take(10)
                ->get();
        }

        return response()->json([
            'status' => 'success',
            'data' => $topStudents
        ]);
    }
}