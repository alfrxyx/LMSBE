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
        $classroomParam = $request->query('classroom_id', '');
        
        // Jika parameter kosong dan user adalah mahasiswa, default ke semester mahasiswa (atau kelasnya)
        if ($classroomParam === '' || $classroomParam === null) {
            if ($user && $user->role === 'student') {
                $userClassroom = $user->semester ? 'sem_' . $user->semester : ($user->classroom_id ? (string) $user->classroom_id : 'all');
            } else {
                $userClassroom = 'all';
            }
        } elseif ($classroomParam === 'semester' && $user) {
            $userClassroom = $user->semester ? 'sem_' . $user->semester : 'all';
        } else {
            $userClassroom = (string) $classroomParam;
        }
        
        $cacheKey = "leaderboard_{$filter}_{$userClassroom}";
        
        $topStudents = Cache::remember($cacheKey, now()->addMinutes(3), function () use ($filter, $userClassroom) {
            $query = User::where('role', 'student');

            if (str_starts_with($userClassroom, 'sem_')) {
                $sem = str_replace('sem_', '', $userClassroom);
                $query->where('semester', $sem);
            } elseif ($userClassroom !== 'all' && !empty($userClassroom)) {
                $query->where(function ($q) use ($userClassroom) {
                    $q->where('classroom_id', $userClassroom)
                      ->orWhereHas('classrooms', function ($cq) use ($userClassroom) {
                          $cq->where('classrooms.id', $userClassroom);
                      });
                });
            }
            
            if ($filter === 'all') {
                $students = $query->orderBy('points', 'desc')
                    ->orderBy('level', 'desc')
                    ->take(50)
                    ->get(['id', 'name', 'points', 'level', 'avatar', 'nim', 'semester', 'bio', 'classroom_id']);
            } else {
                $startDate = $filter === 'weekly' ? Carbon::now()->startOfWeek() : Carbon::now()->startOfMonth();
                
                $students = $query->select('id', 'name', 'level', 'avatar', 'nim', 'semester', 'bio', 'classroom_id')
                    ->selectRaw("(
                        COALESCE((
                            SELECT SUM(COALESCE(NULLIF(up.earned_points, 0), l.xp_reward)) 
                            FROM user_progress up 
                            JOIN levels l ON up.level_id = l.id 
                            WHERE up.user_id = users.id 
                              AND up.is_completed = 1 
                              AND COALESCE(up.completed_at, up.updated_at) >= ?
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
                    ->take(50)
                    ->get();
            }

            // Pastikan casting tipe integer konsisten agar di frontend selalu berupa number
            return $students->map(function ($student) {
                $student->points = (int) $student->points;
                $student->level = (int) $student->level;
                return $student;
            });
        });

        return response()->json([
            'status' => 'success',
            'data' => $topStudents
        ]);
    }

    /**
     * Helper statis untuk membersihkan cache leaderboard secara instan.
     */
    public static function clearCache($classroomId = null)
    {
        $filters = ['all', 'weekly', 'monthly'];
        foreach ($filters as $f) {
            Cache::forget("leaderboard_{$f}_all");
            Cache::forget("leaderboard_{$f}_");
            for ($s = 1; $s <= 8; $s++) {
                Cache::forget("leaderboard_{$f}_sem_{$s}");
            }
            if ($classroomId) {
                Cache::forget("leaderboard_{$f}_{$classroomId}");
            }
        }
    }
}