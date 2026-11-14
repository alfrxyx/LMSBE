<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Course;
use App\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\StudentReminderMail;

class AdminController extends Controller
{
    /**
     * [DOSEN] Mengambil statistik penyelesaian per materi (Level).
     */
    public function getMaterialStats($level_id)
    {
        try {
            $level = \App\Models\Level::with('course')->findOrFail($level_id);

            // Hanya mahasiswa yang tergabung di kelas mata kuliah ini yang dihitung
            $enrolledScope = function ($query) use ($level) {
                $query->where('role', 'student')
                    ->whereHas('classrooms', function ($q) use ($level) {
                        $q->where('classrooms.course_id', $level->course_id);
                    });
            };

            $totalStudents = User::where($enrolledScope)->count();
            
            // Mahasiswa yang SUDAH selesai beserta data progres & nilai
            $completedUsers = User::where($enrolledScope)
                ->whereHas('progress', function($q) use ($level_id) {
                    $q->where('level_id', $level_id)->where('is_completed', true);
                })
                ->with(['progress' => function($q) use ($level_id) {
                    $q->where('level_id', $level_id);
                }])
                ->get()
                ->map(function ($u) {
                    $prog = $u->progress->first();
                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'nim' => $u->nim,
                        'avatar' => $u->avatar,
                        'email' => $u->email,
                        'earned_points' => $prog ? (int)$prog->earned_points : 0,
                        'completed_at' => $prog && $prog->completed_at ? \Carbon\Carbon::parse($prog->completed_at)->format('d M Y, H:i') : ($prog ? \Carbon\Carbon::parse($prog->updated_at)->format('d M Y, H:i') : '-'),
                        'assignment_link' => $prog ? $prog->assignment_link : null,
                        'feedback' => $prog ? $prog->feedback : null,
                    ];
                });

            // Mahasiswa yang BELUM selesai
            $pendingUsers = User::where($enrolledScope)
                ->whereDoesntHave('progress', function($q) use ($level_id) {
                    $q->where('level_id', $level_id)->where('is_completed', true);
                })
                ->get(['id', 'name', 'nim', 'avatar', 'email']);

            $avgPoints = $completedUsers->count() > 0 ? (int) round($completedUsers->avg('earned_points')) : 0;

            return response()->json([
                'status' => 'success',
                'level' => $level,
                'stats' => [
                    'total' => $totalStudents,
                    'completed_count' => $completedUsers->count(),
                    'pending_count' => $pendingUsers->count(),
                    'percentage' => $totalStudents > 0 ? round(($completedUsers->count() / $totalStudents) * 100) : 0,
                    'avg_points' => $avgPoints,
                ],
                'completed_users' => $completedUsers,
                'pending_users' => $pendingUsers
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memuat statistik materi', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * [DOSEN] Mengirim pengingat manual ke notifikasi aplikasi & email mahasiswa.
     */
    public function remindStudent(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'level_id' => 'nullable|exists:levels,id',
            'message' => 'nullable|string|max:500',
        ]);

        try {
            $user = User::findOrFail($validated['user_id']);
            $customMessage = $validated['message'] ?? null;

            if (!empty($validated['level_id'])) {
                $level = \App\Models\Level::with('course')->findOrFail($validated['level_id']);
                $notifTitle = 'Pengingat Materi: ' . $level->title;
                $notifMessage = $customMessage ?: "Dosen mengingatkan Anda untuk segera menyelesaikan materi/tugas \"{$level->title}\" pada mata kuliah " . ($level->course->title ?? 'PJKR') . ".";
                $actionUrl = '/courses/' . $level->course_id;
            } else {
                $notifTitle = 'Pengingat Progres Perkuliahan';
                $notifMessage = $customMessage ?: "Halo {$user->name}, Dosen mengingatkan Anda untuk mengecek dan menyelesaikan materi serta tugas praktikum yang belum tuntas di GamifyLearn PJKR UM.";
                $actionUrl = '/courses';
            }

            // Otomatis membuat Notifikasi In-App sekaligus memicu pengiriman Email HTML via Notification::booted()
            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title' => $notifTitle,
                'message' => $notifMessage,
                'type' => 'warning',
                'action_url' => $actionUrl,
                'is_read' => false,
            ]);

            $liveEnv = file_exists(base_path('.env')) ? \Dotenv\Dotenv::parse(file_get_contents(base_path('.env'))) : [];
            $smtpPass = $liveEnv['MAIL_PASSWORD'] ?? env('MAIL_PASSWORD', '');
            $isSmtpConfigured = !empty($smtpPass) && $smtpPass !== 'xxxx_xxxx_xxxx_xxxx' && $smtpPass !== 'null';

            return response()->json([
                'status' => 'success',
                'smtp_active' => $isSmtpConfigured,
                'message' => $isSmtpConfigured
                    ? "Notifikasi & Email berhasil dikirim ke {$user->email}!"
                    : "Notifikasi in-app terkirim! (Agar masuk ke Inbox Gmail {$user->email}, isi dulu MAIL_PASSWORD 16-digit di file .env)"
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal mengirim pengingat', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mengambil statistik ringkas untuk Dashboard Admin/Dosen.
     * Versi Pedagogis: Fokus pada keberhasilan pembelajaran.
     */
    public function getStats(Request $request)
    {
        $classroomId = $request->query('classroom_id', 'all');
        $semester = $request->query('semester', 'all');

        // Query dasar mahasiswa terfilter
        $studentsQuery = User::where('role', 'student');
        if ($classroomId !== 'all') {
            $studentsQuery->whereHas('classrooms', function($q) use ($classroomId) {
                $q->where('classrooms.id', $classroomId);
            });
        }
        if ($semester !== 'all') {
            $studentsQuery->where('semester', $semester);
        }

        $studentIds = $studentsQuery->pluck('id');
        $totalStudents = $studentsQuery->count();

        // Filter Level sesuai Kelas atau Semester yang dipilih agar grafik akurat
        $levelsQuery = \App\Models\Level::query();
        if ($classroomId !== 'all') {
            $classroomObj = \App\Models\Classroom::find($classroomId);
            if ($classroomObj && $classroomObj->course_id) {
                $levelsQuery->where('course_id', $classroomObj->course_id);
            }
        } elseif ($semester !== 'all') {
            $levelsQuery->whereHas('course', function($q) use ($semester) {
                $q->where('semester', $semester);
            });
        }

        $scopedLevelIds = $levelsQuery->pluck('id');
        $totalLevels = $scopedLevelIds->count();
        
        // 1. Rata-rata Progres Kelas
        $totalProgressCount = \App\Models\UserProgress::whereIn('user_id', $studentIds)
            ->whereIn('level_id', $scopedLevelIds)
            ->where('is_completed', true)
            ->count();
        $avgProgress = ($totalStudents > 0 && $totalLevels > 0) 
            ? round(($totalProgressCount / ($totalStudents * $totalLevels)) * 100) 
            : 0;

        // 2. Capaian Penyelesaian per Pertemuan (Filter berdasarkan level & mahasiswa terpilih)
        $difficultLevels = \App\Models\Level::whereIn('id', $scopedLevelIds)
            ->withCount(['questions', 'progress as completions' => function($q) use ($studentIds) {
                $q->where('is_completed', true)->whereIn('user_id', $studentIds);
            }])
            ->orderBy('completions', 'desc')
            ->orderBy('order', 'asc')
            ->take(6)
            ->get();

        // 3. Mahasiswa "At-Risk"
        $atRiskStudents = User::where('role', 'student')
            ->whereIn('id', $studentIds)
            ->whereDoesntHave('progress', function($q) use ($scopedLevelIds) {
                $q->where('is_completed', true)->whereIn('level_id', $scopedLevelIds);
            })
            ->count();

        // 4. Rasio Kelengkapan Tugas Praktikum (Filter berdasarkan level & mahasiswa terpilih)
        $assignmentLevelIds = \App\Models\Level::whereIn('id', $scopedLevelIds)
            ->where('activity_type', 'assignment')
            ->pluck('id');

        $gradedCount = \App\Models\UserProgress::whereIn('user_id', $studentIds)
            ->whereIn('level_id', $assignmentLevelIds)
            ->where('is_completed', true)
            ->count();

        $pendingCount = \App\Models\UserProgress::whereIn('user_id', $studentIds)
            ->whereIn('level_id', $assignmentLevelIds)
            ->where('is_completed', false)
            ->whereNotNull('assignment_link')
            ->count();

        $totalAssignmentLevels = $assignmentLevelIds->count();
        $totalExpectedSubmissions = $totalStudents * $totalAssignmentLevels;
        $notSubmittedCount = max(0, $totalExpectedSubmissions - ($gradedCount + $pendingCount));

        $assignmentStats = [
            ['name' => 'Selesai & Dinilai', 'value' => $gradedCount],
            ['name' => 'Menunggu Penilaian', 'value' => $pendingCount],
            ['name' => 'Belum Mengumpulkan', 'value' => $notSubmittedCount]
        ];

        $quizAttemptsCount = \App\Models\UserProgress::whereIn('user_id', $studentIds)
            ->whereHas('level', function($q) {
                $q->where('activity_type', 'quiz');
            })
            ->where('is_completed', true)
            ->count();

        $totalCoursesCount = Course::count();
        $activeCoursesCount = Course::where('is_active', true)->count();

        return response()->json([
            'total_students' => $totalStudents,
            'total_courses' => $totalCoursesCount,
            'active_courses' => $activeCoursesCount,
            'completions' => $totalProgressCount,
            'quiz_attempts' => $quizAttemptsCount,
            'pending_assignments' => $pendingCount + Assignment::where('status', 'pending')->count(),
            'total_points_distributed' => User::whereIn('id', $studentIds)->sum('points'),
            'pedagogical_stats' => [
                'avg_class_progress' => $avgProgress,
                'at_risk_count' => $atRiskStudents,
                'difficult_materials' => $difficultLevels->map(function($l) {
                    return ['title' => $l->title, 'completions' => $l->completions];
                }),
                'assignment_stats' => $assignmentStats,
            ]
        ]);
    }

    /**
     * Mengambil data analitik untuk chart/grafik di Analytics.tsx.
     */
    public function getAnalytics(Request $request)
    {
        $range = $request->query('range', '30d');
        $stepDays = match ($range) {
            '7d'  => 1,
            '90d' => 15,
            '1y'  => 60,
            default => 5,
        };

        $totalUsers = User::where('role', 'student')->count();
        $activeUsers = User::where('role', 'student')
            ->where(function ($q) {
                $q->where('points', '>', 0)
                  ->orWhereNotNull('last_activity_date');
            })
            ->count();
        $courseCompletions = \App\Models\UserProgress::where('is_completed', true)->count();
        $videoViews = \App\Models\UserProgress::whereHas('level', function ($q) {
            $q->whereIn('activity_type', ['video', 'assignment']);
        })->count();

        // Data pertumbuhan user & penyelesaian materi 7 titik waktu terakhir sesuai range
        $userGrowth = [];
        $courseCompletionsChart = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i * $stepDays);
            $dateStr = $date->format('Y-m-d');
            $uCount = User::where('role', 'student')
                ->whereDate('created_at', '<=', $date)
                ->count();
            $cCount = \App\Models\UserProgress::where('is_completed', true)
                ->whereDate('updated_at', '<=', $date)
                ->count();

            $userGrowth[] = [
                'date' => $dateStr,
                'count' => $uCount,
            ];
            $courseCompletionsChart[] = [
                'date' => $dateStr,
                'completed' => $cCount,
            ];
        }

        $avgPoints = (int) round(User::where('role', 'student')->avg('points') ?? 0);
        $avgLevel = round(User::where('role', 'student')->avg('level') ?? 1, 1);
        $avgStreak = round(User::where('role', 'student')->avg('current_streak') ?? 0, 1);
        $totalClassrooms = \App\Models\Classroom::count();
        $totalCourses = Course::count();
        $totalLevels = \App\Models\Level::count();
        $totalBadgesEarned = \Illuminate\Support\Facades\DB::table('user_achievements')->count();
        $pendingAssignments = \App\Models\UserProgress::whereHas('level', function ($q) {
            $q->where('activity_type', 'assignment');
        })->whereNotNull('assignment_link')->where('is_completed', false)->count();
        $completedAssignments = \App\Models\UserProgress::whereHas('level', function ($q) {
            $q->where('activity_type', 'assignment');
        })->where('is_completed', true)->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'topStats' => [
                    'totalUsers' => $totalUsers,
                    'activeUsers' => $activeUsers,
                    'courseCompletions' => $courseCompletions,
                    'videoViews' => $videoViews,
                ],
                'userGrowth' => $userGrowth,
                'courseCompletionsChart' => $courseCompletionsChart,
                'detailedMetrics' => [
                    'Rata-rata XP Mahasiswa' => $avgPoints . ' XP',
                    'Rata-rata Level Mahasiswa' => 'Level ' . $avgLevel,
                    'Rata-rata Streak Harian' => $avgStreak . ' Hari',
                    'Total Mata Kuliah' => $totalCourses . ' Mata Kuliah',
                    'Total Kelas Offering' => $totalClassrooms . ' Kelas',
                    'Total Modul & Pertemuan' => $totalLevels . ' Pertemuan',
                    'Tugas Video Lulus' => $completedAssignments . ' Video',
                    'Tugas Menunggu Evaluasi' => $pendingAssignments . ' Video',
                    'Total Lencana Diraih' => $totalBadgesEarned . ' Lencana',
                ],
            ]
        ]);
    }

    /**
     * Mengelola data mahasiswa (StudentManagement.tsx).
     */
    public function indexUsers(Request $request)
    {
        $semester = $request->query('semester', 'all');
        $totalLevelsGlobal = \App\Models\Level::count();
        
        $query = User::where('role', 'student')
            ->withCount(['progress as completed_levels_count' => function ($query) {
                $query->where('is_completed', true);
            }])
            ->withCount(['progress as youtube_submissions_count' => function ($query) {
                $query->whereNotNull('assignment_link')
                      ->where(function($q) {
                          $q->where('assignment_link', 'LIKE', '%youtube.com%')
                            ->orWhere('assignment_link', 'LIKE', '%youtu.be%');
                      });
            }])
            ->with(['achievements', 'classrooms.course.levels', 'progress.level.course']);
        
        if ($semester !== 'all') {
            $query->where('semester', $semester);
        }

        $users = $query->orderBy('points', 'desc')->get();

        $users->transform(function($user) use ($totalLevelsGlobal) {
            // Hitung total level dari kelas/mata kuliah yang diikuti mahasiswa agar persentase akurat
            $joinedCourseLevels = 0;
            if ($user->classrooms && $user->classrooms->count() > 0) {
                $uniqueCourses = $user->classrooms->pluck('course')->filter()->unique('id');
                foreach ($uniqueCourses as $course) {
                    $joinedCourseLevels += $course->levels ? $course->levels->count() : 0;
                }
            }
            $targetTotalLevels = $joinedCourseLevels > 0 ? $joinedCourseLevels : $totalLevelsGlobal;

            $user->total_target_levels = $targetTotalLevels;
            $user->progress_percentage = $targetTotalLevels > 0 
                ? min(100, round(($user->completed_levels_count / $targetTotalLevels) * 100)) 
                : 0;
            return $user;
        });
            
        return response()->json($users);
    }

    /**
     * Mengambil daftar pengumpulan tugas YouTube dari tabel user_progress.
     * Mendukung filter per semester.
     */
    public function getYoutubeSubmissions(Request $request)
    {
        $semester = $request->query('semester', 'all');
        $classroomId = $request->query('classroom_id', 'all');

        $query = \App\Models\UserProgress::with(['user', 'level.course'])
            ->whereHas('level', function($q) {
                $q->where('activity_type', 'assignment');
            })
            ->whereNotNull('assignment_link')
            ->where(function($q) {
                $q->where('assignment_link', 'LIKE', '%youtube.com%')
                  ->orWhere('assignment_link', 'LIKE', '%youtu.be%');
            });

        if ($semester !== 'all') {
            $query->whereHas('level.course', function($q) use ($semester) {
                $q->where('semester', $semester);
            });
        }

        if ($classroomId !== 'all') {
            $query->whereHas('user.classrooms', function($q) use ($classroomId) {
                $q->where('classrooms.id', $classroomId);
            });
        }

        $submissions = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $submissions
        ]);
    }

    /**
     * [DOSEN] Fitur Monitoring Mahasiswa: Melihat progres detail setiap mahasiswa.
     */
    public function getStudentMonitoring(Request $request)
    {
        try {
            $studentsQuery = User::where('role', 'student');

            // Saring berdasarkan kelas (Many-to-Many via pivot classroom_user)
            if ($request->filled('classroom_id')) {
                $studentsQuery->whereHas('classrooms', function($q) use ($request) {
                    $q->where('classrooms.id', $request->classroom_id);
                });
            }

            // Gunakan Eager Loading Progres Selesai
            $students = $studentsQuery
                ->with([
                    'progress' => function($q) {
                        $q->where('is_completed', true);
                    },
                    'classrooms',
                ])
                ->orderBy('name', 'asc')
                ->get();

            // Peta level per mata kuliah (diambil sekali, bukan dalam loop)
            $levelIdsByCourse = \App\Models\Level::select('id', 'course_id')->get()
                ->groupBy('course_id')
                ->map(fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id));
            $allLevelIds = $levelIdsByCourse->flatten();
            $totalAvailableLevels = $allLevelIds->count();

            // Jika disaring per kelas, target = seluruh pertemuan mata kuliah kelas tersebut
            $filterCourseId = null;
            if ($request->filled('classroom_id')) {
                $filterCourseId = \App\Models\Classroom::where('id', $request->classroom_id)->value('course_id');
            }

            $monitoringData = $students->map(function ($student) use ($levelIdsByCourse, $allLevelIds, $filterCourseId) {
                // Target tiap mahasiswa = pertemuan dari mata kuliah yang kelasnya ia ikuti
                $courseIds = $filterCourseId
                    ? collect([$filterCourseId])
                    : $student->classrooms->pluck('course_id')->filter()->unique();
                $targetIds = $courseIds->flatMap(fn ($cid) => $levelIdsByCourse->get($cid, collect()))->unique()->values();
                if ($targetIds->isEmpty()) {
                    $targetIds = $allLevelIds;
                }

                $completedIds = $student->progress->pluck('level_id')->map(fn ($id) => (int) $id);
                $completedCount = $completedIds->intersect($targetIds)->count();
                $targetTotal = $targetIds->count();

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'nim' => $student->nim,
                    'semester' => $student->semester,
                    'points' => $student->points,
                    'level' => $student->level,
                    'avatar' => $student->avatar,
                    'classroom_id' => $student->classroom_id,
                    'completed_count' => $completedCount,
                    'completed_level_ids' => $student->progress->pluck('level_id'),
                    'progress_percentage' => $targetTotal > 0
                        ? round(($completedCount / $targetTotal) * 100)
                        : 0,
                    'last_activity' => $student->updated_at ? $student->updated_at->diffForHumans() : 'Belum aktif',
                    'current_streak' => $student->current_streak ?? 0,
                ];
            });

            return response()->json([
                'status' => 'success',
                'total_system_levels' => $totalAvailableLevels,
                'data' => $monitoringData
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memuat data monitoring', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Admin/Dosen membuat kursus/tugas baru (ContentManagement.tsx).
     * Mendukung upload thumbnail foto secara lokal.
     */
    public function createCourse(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'semester' => 'required|string',
                'total_points' => 'nullable|integer',
                'order' => 'sometimes|integer',
                'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
                'thumbnail_url' => 'nullable|string',
                'offering_name' => 'nullable|string|max:255',
            ], [
                'title.required' => 'Judul mata kuliah wajib diisi.',
                'description.required' => 'Deskripsi mata kuliah wajib diisi.',
                'semester.required' => 'Target semester wajib dipilih.',
                'thumbnail.image' => 'File thumbnail harus berupa gambar.',
                'thumbnail.max' => 'Ukuran foto thumbnail maksimal 10MB.',
            ]);

            $thumbnailPath = 'https://images.unsplash.com/photo-1546519638-68e109498ffc?w=800';

            if ($request->hasFile('thumbnail')) {
                $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'public');
            } elseif ($request->filled('thumbnail_url')) {
                $thumbnailPath = $request->thumbnail_url;
            }

            $course = Course::create([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'semester' => $validated['semester'],
                'total_points' => $validated['total_points'] ?? 0,
                'order' => $validated['order'] ?? 1,
                'thumbnail' => $thumbnailPath,
                // Dosen yang membuat mata kuliah otomatis menjadi pengampu (jika kolom sudah dimigrasi)
                ...(\Illuminate\Support\Facades\Schema::hasColumn('courses', 'instructor_id') && auth()->user()?->role === 'dosen'
                    ? ['instructor_id' => auth()->id()]
                    : []),
            ]);

            // Otomatis buat Kelas / Offering awal beserta Kode Token PJKR-XXXX jika diisi
            $offeringName = trim($request->input('offering_name', 'Offering A'));
            if (!empty($offeringName)) {
                do {
                    $code = 'PJKR-' . strtoupper(\Illuminate\Support\Str::random(4));
                } while (\App\Models\Classroom::where('code', $code)->exists());

                \App\Models\Classroom::create([
                    'name' => $offeringName,
                    'code' => $code,
                    'course_id' => $course->id,
                    'semester' => $course->semester,
                    'is_active' => true,
                ]);
            }

            $course->load(['classrooms' => function($q) {
                $q->withCount('students');
            }]);

            return response()->json(['message' => 'Kursus dan Kelas Offering berhasil ditambahkan!', 'data' => $course], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first();
            return response()->json([
                'message' => $firstError,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('CreateCourse error: ' . $e->getMessage());
            return response()->json(['message' => 'Gagal menyimpan materi: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Memperbarui data kursus yang sudah ada.
     */
    public function updateCourse(Request $request, $id)
    {
        try {
            $course = Course::findOrFail($id);
            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'description' => 'sometimes|required|string',
                'semester' => 'sometimes|required|string',
                'total_points' => 'nullable|integer',
                'order' => 'sometimes|integer',
                'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
                'thumbnail_url' => 'nullable|string',
            ], [
                'title.required' => 'Judul mata kuliah wajib diisi.',
                'description.required' => 'Deskripsi mata kuliah wajib diisi.',
                'thumbnail.image' => 'File thumbnail harus berupa gambar.',
                'thumbnail.max' => 'Ukuran foto thumbnail maksimal 10MB.',
            ]);

            $data = $validated;

            if ($request->hasFile('thumbnail')) {
                if ($course->thumbnail && !str_contains($course->thumbnail, 'http')) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($course->thumbnail);
                }
                $data['thumbnail'] = $request->file('thumbnail')->store('thumbnails', 'public');
            } elseif ($request->filled('thumbnail_url')) {
                $data['thumbnail'] = $request->thumbnail_url;
            }

            $course->update($data);

            return response()->json(['message' => 'Mata kuliah berhasil diperbarui!', 'data' => $course]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memperbarui materi', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Menghapus kursus (Opsional untuk fitur Delete).
     */
    public function deleteCourse($id)
    {
        $course = Course::findOrFail($id);
        $course->delete();
        return response()->json(['message' => 'Kursus berhasil dihapus']);
    }

    /**
     * [DOSEN/ADMIN] Memperbarui data profil mahasiswa (termasuk Semester).
     */
    public function updateStudent(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            if ($user->role !== 'student') {
                return response()->json(['message' => 'Hanya data mahasiswa yang dapat diubah.'], 403);
            }

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'nim' => 'sometimes|required|string|unique:users,nim,' . $id,
                'email' => 'sometimes|required|email|unique:users,email,' . $id,
                'phone' => 'nullable|string',
                'semester' => 'sometimes|required|integer|min:1|max:8',
            ]);

            $user->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Data mahasiswa berhasil diperbarui!',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memperbarui data mahasiswa', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * [ADMIN/DOSEN] Menambahkan akun mahasiswa baru secara manual.
     */
    public function storeStudent(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'nim' => 'required|string|unique:users,nim',
                'email' => 'required|email|unique:users,email',
                'phone' => 'nullable|string',
                'semester' => 'required|integer|min:1|max:8',
                'password' => 'nullable|string|min:8',
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'nim' => $validated['nim'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? '',
                'semester' => (string) $validated['semester'],
                'password' => \Illuminate\Support\Facades\Hash::make($validated['password'] ?? $validated['nim']),
                'role' => 'student',
                'points' => 0,
                'level' => 1,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Mahasiswa berhasil ditambahkan!',
                'data' => $user
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menambahkan mahasiswa: ' . $e->getMessage()], 422);
        }
    }

    /**
     * [ADMIN/DOSEN] Menghapus akun mahasiswa.
     */
    public function deleteStudent($id)
    {
        try {
            $user = User::findOrFail($id);
            if ($user->role !== 'student') {
                return response()->json(['message' => 'Hanya akun mahasiswa yang dapat dihapus.'], 403);
            }
            $user->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Data mahasiswa berhasil dihapus!'
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menghapus mahasiswa', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * [ADMIN/DOSEN] Kenaikan Semester Massal (Opsi C: 1-Klik Naik Semester Angkatan).
     */
    public function promoteSemesterBulk(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_semester' => 'required|integer|min:1|max:7',
                'to_semester' => 'nullable|integer|min:2|max:8',
            ]);

            $fromSem = (int) $validated['from_semester'];
            $toSem = isset($validated['to_semester']) ? (int) $validated['to_semester'] : ($fromSem + 1);

            $count = User::where('role', 'student')
                ->where('semester', (string) $fromSem)
                ->update(['semester' => (string) $toSem]);

            return response()->json([
                'status' => 'success',
                'promoted_count' => $count,
                'to_semester' => (string) $toSem,
                'message' => $count > 0
                    ? "Berhasil menaikkan {$count} mahasiswa dari Semester {$fromSem} ke Semester {$toSem}!"
                    : "Tidak ada mahasiswa di Semester {$fromSem} untuk dinaikkan."
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menaikkan semester massal: ' . $e->getMessage()], 500);
        }
    }

    /**
     * [ADMIN/DOSEN] Ekspor Dataset Penelitian Lengkap (Untuk Analisis SPSS / Skripsi Metopen PJKR UM).
     */
    public function exportResearchDataset(Request $request)
    {
        try {
            $semester = $request->query('semester', 'all');
            $classroomId = $request->query('classroom_id', 'all');

            $studentsQuery = User::where('role', 'student')
                ->with(['classrooms', 'progress.level', 'achievements']);

            if ($semester !== 'all') {
                $studentsQuery->where('semester', $semester);
            }
            if ($classroomId !== 'all') {
                $studentsQuery->whereHas('classrooms', function ($q) use ($classroomId) {
                    $q->where('classrooms.id', $classroomId);
                });
            }

            $students = $studentsQuery->orderBy('name', 'asc')->get();
            $totalLevels = \App\Models\Level::count();

            $csvHeader = [
                'NIM',
                'Nama_Mahasiswa',
                'Email',
                'Semester',
                'Kelas',
                'Total_XP',
                'Level_Gamifikasi',
                'Current_Streak_Hari',
                'Total_Pertemuan_Selesai',
                'Persentase_Progres_Belajar',
                'Total_Kuis_Dikerjakan',
                'Rata_Rata_Skor_Kuis',
                'Skor_Rubrik_Awalan_Avg',
                'Skor_Rubrik_Pelaksanaan_Avg',
                'Skor_Rubrik_Akhiran_Avg',
                'Total_Tugas_Praktik_Dinilai',
                'Jumlah_Lencana_Diraih',
                'Aktivitas_Terakhir'
            ];

            $csvRows = [];
            $csvRows[] = $csvHeader;

            foreach ($students as $student) {
                $completedProgress = $student->progress->where('is_completed', true);
                $completedCount = $completedProgress->count();
                $pctProgress = $totalLevels > 0 ? round(($completedCount / $totalLevels) * 100, 1) : 0;

                // Hitung rata-rata kuis
                $quizScores = [];
                foreach ($student->progress as $p) {
                    if ($p->level && $p->level->activity_type === 'quiz' && $p->assignment_link) {
                        if (preg_match('/Quiz Score: (\d+)%/', $p->assignment_link, $m)) {
                            $quizScores[] = (int) $m[1];
                        }
                    }
                }
                $avgQuizScore = count($quizScores) > 0 ? round(array_sum($quizScores) / count($quizScores), 1) : 0;

                // Hitung nilai rubrik motorik (Awalan, Pelaksanaan, Akhiran)
                $prepScores = [];
                $execScores = [];
                $followScores = [];
                $gradedPracticeCount = 0;

                foreach ($completedProgress as $p) {
                    if ($p->level && $p->level->activity_type === 'assignment') {
                        $rubric = $p->rubric_scores;
                        if (!$rubric && $p->feedback && str_contains($p->feedback, 'RUBRIC_DATA:')) {
                            try {
                                $parts = explode('|FEEDBACK:', $p->feedback);
                                $jsonStr = str_replace('RUBRIC_DATA:', '', $parts[0]);
                                $rubric = json_decode($jsonStr, true);
                            } catch (\Throwable $e) {}
                        }

                        if ($rubric) {
                            $gradedPracticeCount++;
                            if (isset($rubric['prep']) || isset($rubric['preparation'])) {
                                $prepScores[] = (float) ($rubric['prep'] ?? $rubric['preparation']);
                            }
                            if (isset($rubric['exec']) || isset($rubric['execution'])) {
                                $execScores[] = (float) ($rubric['exec'] ?? $rubric['execution']);
                            }
                            if (isset($rubric['follow']) || isset($rubric['follow_through'])) {
                                $followScores[] = (float) ($rubric['follow'] ?? $rubric['follow_through']);
                            }
                        }
                    }
                }

                $avgPrep = count($prepScores) > 0 ? round(array_sum($prepScores) / count($prepScores), 2) : 0;
                $avgExec = count($execScores) > 0 ? round(array_sum($execScores) / count($execScores), 2) : 0;
                $avgFollow = count($followScores) > 0 ? round(array_sum($followScores) / count($followScores), 2) : 0;

                $classNames = $student->classrooms->pluck('name')->join('; ') ?: '-';

                $csvRows[] = [
                    $student->nim,
                    $student->name,
                    $student->email,
                    $student->semester,
                    $classNames,
                    $student->points,
                    $student->level,
                    $student->current_streak ?? 0,
                    $completedCount,
                    $pctProgress,
                    count($quizScores),
                    $avgQuizScore,
                    $avgPrep,
                    $avgExec,
                    $avgFollow,
                    $gradedPracticeCount,
                    $student->achievements->count(),
                    $student->last_activity_date ? $student->last_activity_date->format('Y-m-d') : ($student->updated_at ? $student->updated_at->format('Y-m-d') : '-')
                ];
            }

            return response()->json([
                'status' => 'success',
                'data' => $csvRows
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal mengekspor dataset: ' . $e->getMessage()], 500);
        }
    }
}