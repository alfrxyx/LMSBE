<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\UserProgress;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    /**
     * Menampilkan daftar Mata Kuliah aktif
     */
    public function index()
    {
        try {
            $user = auth()->user();
            
            // Urutkan Course berdasarkan semester dan order
            $query = Course::with(['levels' => function($query) {
                $query->orderBy('order', 'asc')->orderBy('id', 'asc');
            }])->where('is_active', true)
               ->orderBy('semester', 'asc')
               ->orderBy('order', 'asc')
               ->orderBy('id', 'asc');

            if ($user->role === 'student') {
                $query->where('semester', $user->semester);
            }

            $courses = $query->get();

            // AMBIL SEMUA PROGRES USER UNTUK PENGECEKAN SEQUENTIAL COURSE
            $allCompletedLevels = UserProgress::where('user_id', $user->id)
                ->where('is_completed', true)
                ->pluck('level_id')
                ->toArray();

            $previousCourseCompleted = true;

            // Transformasi data
            $formattedCourses = $courses->map(function ($course) use ($allCompletedLevels, &$previousCourseCompleted, $user) {
                $courseArray = $course->toArray();
                
                // Hitung apakah course ini sudah selesai (semua levelnya tuntas)
                $totalLevels = count($courseArray['levels'] ?? []);
                $completedInThisCourse = 0;

                if ($totalLevels > 0) {
                    foreach ($courseArray['levels'] as $key => $level) {
                        $isComp = in_array($level['id'], $allCompletedLevels);
                        $courseArray['levels'][$key]['is_completed'] = $isComp;
                        if ($isComp) $completedInThisCourse++;
                    }
                }

                $isCourseDone = $totalLevels === 0 || ($completedInThisCourse === $totalLevels);
                
                // Mahasiswa: Cek urutan Course
                if ($user->role === 'student') {
                    $courseArray['can_access'] = $previousCourseCompleted;
                } else {
                    $courseArray['can_access'] = true;
                }

                // Update untuk iterasi berikutnya
                // Jika materi ini bisa diakses, maka status 'selesai' materi ini menentukan akses materi selanjutnya
                if ($courseArray['can_access']) {
                    $previousCourseCompleted = $isCourseDone;
                } else {
                    // Jika materi ini saja belum bisa diakses, maka materi selanjutnya pasti tidak bisa
                    $previousCourseCompleted = false;
                }

                return $courseArray;
            });
            
            return response()->json([
                'status' => 'success',
                'data' => $formattedCourses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil daftar materi: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menampilkan detail Mata Kuliah beserta strukturnya.
     */
    public function show($id)
    {
        try {
            $user = auth()->user();

            $course = Course::with(['levels' => function($query) {
                // Urutkan secara ketat berdasarkan order, lalu ID sebagai fallback
                $query->orderBy('order', 'asc')->orderBy('id', 'asc')->with(['questions.options']);
            }])->findOrFail($id);

            // CEK APAKAH MAHASISWA BERADA DI SEMESTER YANG SAMA DENGAN MATA KULIAH (Sinkronisasi Naskah)
            if ($user->role === 'student' && $course->semester != $user->semester) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Anda tidak memiliki akses ke Mata Kuliah semester lain.'
                ], 403);
            }

            // CEK APAKAH COURSE SEBELUMNYA SUDAH SELESAI (Strict Course Sequence)
            if ($user->role === 'student') {
                $previousCourse = Course::where('semester', $course->semester)
                    ->where(function($q) use ($course) {
                        $q->where('order', '<', $course->order)
                          ->orWhere(function($q2) use ($course) {
                              $q2->where('order', $course->order)->where('id', '<', $course->id);
                          });
                    })
                    ->orderBy('order', 'desc')
                    ->orderBy('id', 'desc')
                    ->first();

                if ($previousCourse) {
                    $totalPrevLevels = $previousCourse->levels()->count();
                    $completedPrevLevels = UserProgress::where('user_id', $user->id)
                        ->whereIn('level_id', $previousCourse->levels()->pluck('id'))
                        ->where('is_completed', true)
                        ->count();

                    if ($totalPrevLevels === 0 || $completedPrevLevels < $totalPrevLevels) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Selesaikan Mata Kuliah "' . $previousCourse->title . '" terlebih dahulu.'
                        ], 403);
                    }
                }
            }

            // AMBIL DATA PROGRES USER UNTUK LEVEL DI COURSE INI
            $userProgress = UserProgress::where('user_id', $user->id)
                ->whereIn('level_id', $course->levels->pluck('id'))
                ->get()
                ->keyBy('level_id');

            $courseArray = $course->toArray();
            $previousLevelCompleted = true;

            if (isset($courseArray['levels'])) {
                foreach ($courseArray['levels'] as $key => $level) {
                    $progress = $userProgress->get($level['id']);
                    $isDone = $progress ? $progress->is_completed : false;
                    
                    $courseArray['levels'][$key]['is_completed'] = $isDone;
                    $courseArray['levels'][$key]['feedback'] = $progress ? $progress->feedback : null;
                    $courseArray['levels'][$key]['earned_points'] = $progress ? $progress->earned_points : 0;
                    
                    // Level pertama (berdasarkan urutan sort tadi) selalu terbuka
                    if ($key === 0) {
                        $courseArray['levels'][$key]['can_access'] = true;
                    } else {
                        $courseArray['levels'][$key]['can_access'] = $previousLevelCompleted;
                    }
                    
                    // Siapkan status untuk level berikutnya (sequential access)
                    $previousLevelCompleted = $isDone;
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => $courseArray
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memuat detail materi: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menambahkan Mata Kuliah baru (Hanya untuk Role Admin/Dosen PJKR)
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'description' => 'required|string',
            'thumbnail' => 'nullable|string',
            'total_points' => 'required|integer'
        ]);

        $course = Course::create($request->all());

        return response()->json([
            'message' => 'Mata Kuliah berhasil dibuat',
            'data' => $course
        ], 201);
    }
}