<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Course;
use App\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * Mengambil statistik ringkas untuk Dashboard Admin/Dosen.
     * Versi Pedagogis: Fokus pada keberhasilan pembelajaran.
     */
    public function getStats()
    {
        $totalStudents = User::where('role', 'student')->count();
        $totalLevels = \App\Models\Level::count();
        
        // 1. Rata-rata Progres Kelas
        $totalProgressCount = \App\Models\UserProgress::where('is_completed', true)->count();
        $avgProgress = ($totalStudents > 0 && $totalLevels > 0) 
            ? round(($totalProgressCount / ($totalStudents * $totalLevels)) * 100) 
            : 0;

        // 2. Materi Paling Sulit (Berdasarkan jumlah mahasiswa yang menyelesaikan)
        $difficultLevels = \App\Models\Level::withCount(['questions', 'progress as completions' => function($q) {
                $q->where('is_completed', true);
            }])
            ->orderBy('completions', 'asc')
            ->take(3)
            ->get();

        // 3. Mahasiswa "At-Risk" (Kurang aktif - belum menyelesaikan progres apapun)
        $atRiskStudents = User::where('role', 'student')
            ->whereDoesntHave('progress', function($q) {
                $q->where('is_completed', true);
            })
            ->count();

        return response()->json([
            'total_students' => $totalStudents,
            'total_courses' => Course::count(),
            'pending_assignments' => Assignment::where('status', 'pending')->count(),
            'total_points_distributed' => User::sum('points'),
            'pedagogical_stats' => [
                'avg_class_progress' => $avgProgress,
                'at_risk_count' => $atRiskStudents,
                'difficult_materials' => $difficultLevels->map(function($l) {
                    return ['title' => $l->title, 'completions' => $l->completions];
                }),
            ]
        ]);
    }

    /**
     * Mengambil data analitik untuk chart/grafik di Analytics.tsx.
     */
    public function getAnalytics()
    {
        // Mengambil rata-rata poin per level mahasiswa PJKR
        $analytics = User::where('role', 'student')
            ->select('level', DB::raw('AVG(points) as avg_points'))
            ->groupBy('level')
            ->get();

        return response()->json($analytics);
    }

    /**
     * Mengelola data mahasiswa (StudentManagement.tsx).
     */
    public function indexUsers(Request $request)
    {
        $semester = $request->query('semester', 'all');
        
        $query = User::where('role', 'student');
        
        if ($semester !== 'all') {
            $query->where('semester', $semester);
        }

        $users = $query->orderBy('points', 'desc') 
            ->get();
            
        return response()->json($users);
    }

    /**
     * Mengambil daftar pengumpulan tugas YouTube dari tabel user_progress.
     * Mendukung filter per semester.
     */
    public function getYoutubeSubmissions(Request $request)
    {
        $semester = $request->query('semester', 'all');

        $query = \App\Models\UserProgress::with(['user', 'level.course'])
            ->whereHas('level', function($q) {
                $q->where('activity_type', 'assignment');
            })
            ->whereNotNull('assignment_link')
            ->where('assignment_link', 'LIKE', '%youtube.com%')
            ->orWhere('assignment_link', 'LIKE', '%youtu.be%');

        if ($semester !== 'all') {
            $query->whereHas('level.course', function($q) use ($semester) {
                $q->where('semester', $semester);
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
    public function getStudentMonitoring()
    {
        try {
            // Gunakan Eager Loading Progres Selesai
            $students = User::where('role', 'student')
                ->withCount(['progress as completed_levels_count' => function ($query) {
                    $query->where('is_completed', true);
                }])
                ->orderBy('name', 'asc')
                ->get();

            // Total level diambil satu kali (Bukan dalam loop)
            $totalAvailableLevels = \App\Models\Level::count();

            $monitoringData = $students->map(function ($student) use ($totalAvailableLevels) {
                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'nim' => $student->nim,
                    'semester' => $student->semester,
                    'points' => $student->points,
                    'level' => $student->level,
                    'avatar' => $student->avatar,
                    'completed_count' => (int) $student->completed_levels_count,
                    'progress_percentage' => $totalAvailableLevels > 0 
                        ? round(($student->completed_levels_count / $totalAvailableLevels) * 100) 
                        : 0,
                    'last_activity' => $student->updated_at ? $student->updated_at->diffForHumans() : 'Belum aktif',
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
                'total_points' => 'required|integer',
                'order' => 'sometimes|integer',
                'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
                'thumbnail_url' => 'nullable|string',
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
                'total_points' => $validated['total_points'],
                'order' => $validated['order'] ?? 1,
                'thumbnail' => $thumbnailPath,
            ]);

            return response()->json(['message' => 'Kursus berhasil ditambahkan!', 'data' => $course], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menyimpan materi', 'error' => $e->getMessage()], 500);
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
                'total_points' => 'sometimes|required|integer',
                'order' => 'sometimes|integer',
                'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
                'thumbnail_url' => 'nullable|string',
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
}