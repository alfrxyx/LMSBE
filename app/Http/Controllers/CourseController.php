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
            $query = Course::with([
                'levels' => function($query) {
                    $query->orderBy('order', 'asc')->orderBy('id', 'asc');
                },
                'classrooms' => function($query) {
                    $query->withCount('students')->orderBy('id', 'asc');
                }
            ])->where('is_active', true)
               ->orderBy('semester', 'asc')
               ->orderBy('order', 'asc')
               ->orderBy('id', 'asc');

            $joinedCourseIds = [];
            if ($user->role === 'student') {
                $joinedCourseIds = $user->classrooms()->pluck('course_id')->toArray();
            }

            $courses = $query->get();

            // AMBIL SEMUA PROGRES USER UNTUK PENGECEKAN SEQUENTIAL COURSE
            $allCompletedLevels = UserProgress::where('user_id', $user->id)
                ->where('is_completed', true)
                ->pluck('level_id')
                ->toArray();

            $previousCourseCompleted = true;

            // Transformasi data
            $formattedCourses = $courses->map(function ($course) use ($allCompletedLevels, &$previousCourseCompleted, $user, $joinedCourseIds) {
                $courseArray = $course->toArray();
                
                // Cek apakah mahasiswa terdaftar di kelas untuk matkul ini
                $isJoined = $user->role !== 'student' || in_array($course->id, $joinedCourseIds);
                $courseArray['is_joined'] = $isJoined;
                
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
                    if ($isJoined) {
                        $courseArray['can_access'] = $previousCourseCompleted;
                        $previousCourseCompleted = $isCourseDone;
                    } else {
                        $courseArray['can_access'] = false;
                    }
                } else {
                    $courseArray['can_access'] = true;
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

            // CEK HAK AKSES MAHASISWA BERDASARKAN KELAS YANG DIIKUTI
            $userCourseIds = [];
            if ($user->role === 'student') {
                $userCourseIds = $user->classrooms()->pluck('course_id')->toArray();
                if (!in_array($course->id, $userCourseIds)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Anda tidak memiliki akses ke Mata Kuliah ini. Silakan hubungi dosen Anda untuk bergabung ke kelas.'
                    ], 403);
                }
            }

            // CEK APAKAH COURSE SEBELUMNYA SUDAH SELESAI (Strict Course Sequence)
            // Aturan SAMA dengan index(): rantai berurutan lintas semester (semester -> order -> id)
            if ($user->role === 'student') {
                $previousCourse = Course::where('is_active', true)
                    ->whereIn('id', $userCourseIds)
                    ->where(function($q) use ($course) {
                        $q->where('semester', '<', $course->semester)
                          ->orWhere(function($q1) use ($course) {
                              $q1->where('semester', $course->semester)
                                 ->where(function($q2) use ($course) {
                                     $q2->where('order', '<', $course->order)
                                        ->orWhere(function($q3) use ($course) {
                                            $q3->where('order', $course->order)->where('id', '<', $course->id);
                                        });
                                 });
                          });
                    })
                    ->orderBy('semester', 'desc')
                    ->orderBy('order', 'desc')
                    ->orderBy('id', 'desc')
                    ->first();

                if ($previousCourse) {
                    $totalPrevLevels = $previousCourse->levels()->count();
                    $completedPrevLevels = UserProgress::where('user_id', $user->id)
                        ->whereIn('level_id', $previousCourse->levels()->pluck('id'))
                        ->where('is_completed', true)
                        ->count();

                    if ($totalPrevLevels > 0 && $completedPrevLevels < $totalPrevLevels) {
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
                    $hasSubmitted = ($level['activity_type'] === 'assignment' && $progress && !empty($progress->assignment_link));
                    
                    $courseArray['levels'][$key]['is_completed'] = $isDone;
                    $courseArray['levels'][$key]['has_submitted'] = $hasSubmitted;
                    $courseArray['levels'][$key]['assignment_link'] = $progress ? $progress->assignment_link : null;
                    $courseArray['levels'][$key]['feedback'] = $progress ? $progress->feedback : null;
                    $courseArray['levels'][$key]['earned_points'] = $progress ? $progress->earned_points : 0;

                    // Sembunyikan kunci jawaban kuis (is_correct) & acak urutan pilihan (anti-cheat) untuk mahasiswa
                    if ($user->role === 'student' && !$isDone && !empty($courseArray['levels'][$key]['questions'])) {
                        foreach ($courseArray['levels'][$key]['questions'] as $qIndex => $question) {
                            if (!empty($question['options'])) {
                                // Acak opsi jawaban agar tidak bisa saling sontek posisi A/B/C/D
                                shuffle($courseArray['levels'][$key]['questions'][$qIndex]['options']);
                                foreach ($courseArray['levels'][$key]['questions'][$qIndex]['options'] as $oIndex => $option) {
                                    unset($courseArray['levels'][$key]['questions'][$qIndex]['options'][$oIndex]['is_correct']);
                                }
                            }
                        }
                    }
                    
                    // Level pertama (berdasarkan urutan sort tadi) selalu terbuka
                    if ($key === 0) {
                        $courseArray['levels'][$key]['can_access'] = true;
                    } else {
                        $courseArray['levels'][$key]['can_access'] = $previousLevelCompleted;
                    }
                    
                    // JADWAL BUKA: sembunyikan isi pertemuan dari mahasiswa sebelum waktu open_at tiba
                    $levelModel = $course->levels[$key] ?? null;
                    $opensInFuture = $levelModel && $levelModel->open_at && now()->lessThan($levelModel->open_at);
                    $courseArray['levels'][$key]['opens_in_future'] = false;
                    if ($user->role === 'student' && $opensInFuture) {
                        $courseArray['levels'][$key]['opens_in_future'] = true;
                        $courseArray['levels'][$key]['can_access'] = false;
                        $courseArray['levels'][$key]['description'] = '';
                        $courseArray['levels'][$key]['pdf_path'] = null;
                        $courseArray['levels'][$key]['youtube_id'] = null;
                        $courseArray['levels'][$key]['questions'] = [];
                    }

                    // Siapkan status untuk level berikutnya (boleh lanjut jika sudah dinilai ATAU jika tipe tugas & link sudah dikirim)
                    $previousLevelCompleted = ($isDone || $hasSubmitted);
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