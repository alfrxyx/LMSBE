<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use Illuminate\Support\Facades\Cache;

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
        $classroomParam = $request->query('classroom_id', 'all');
        
        $userClassroom = $user && $user->role === 'student' ? (string) $user->classroom_id : $classroomParam;
        
        $cacheKey = "leaderboard_{$filter}_{$userClassroom}";
        
        $topStudents = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($filter, $userClassroom) {
            $query = User::where('role', 'student');

            if ($userClassroom !== 'all' && $userClassroom !== '') {
                $query->where('classroom_id', $userClassroom);
            }
            
            if ($filter === 'all') {
                return $query->orderBy('points', 'desc')
                    ->orderBy('level', 'desc')
                    ->take(10)
                    ->get(['id', 'name', 'points', 'level', 'avatar', 'nim', 'semester', 'bio', 'classroom_id']);
            } else {
                $startDate = $filter === 'weekly' ? Carbon::now()->startOfWeek() : Carbon::now()->startOfMonth();
                
                return $query->select('id', 'name', 'level', 'avatar', 'nim', 'semester', 'bio', 'classroom_id')
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
        });

        return response()->json([
            'status' => 'success',
            'data' => $topStudents
        ]);
    }
}