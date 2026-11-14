<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class ClassroomController extends Controller
{
    /**
     * Menampilkan semua kelas beserta jumlah mahasiswanya.
     */
    public function index()
    {
        $classrooms = Classroom::with(['course'])->withCount('students')->get();
        return response()->json([
            'status' => 'success',
            'data' => $classrooms
        ]);
    }

    /**
     * Dosen membuat kelas baru dengan generate token acak unik.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'course_id' => 'required|exists:courses,id',
        ]);

        $course = \App\Models\Course::findOrFail($request->course_id);

        // Generate token kelas acak unik (e.g., PJKR-A3B9)
        do {
            $code = 'PJKR-' . strtoupper(Str::random(4));
        } while (Classroom::where('code', $code)->exists());

        $classroom = Classroom::create([
            'name' => $request->name,
            'code' => $code,
            'course_id' => $course->id,
            'semester' => $course->semester,
            'is_active' => true,
        ]);

        $classroom->load('course');

        return response()->json([
            'status' => 'success',
            'message' => 'Kelas berhasil dibuat!',
            'data' => $classroom
        ], 201);
    }

    /**
     * Menghapus kelas.
     */
    public function destroy($id)
    {
        $classroom = Classroom::findOrFail($id);
        $classroom->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Kelas berhasil dihapus!'
        ]);
    }

    /**
     * Mahasiswa bergabung ke kelas berdasarkan kode kelas.
     */
    public function join(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        // Bersihkan spasi dan ubah ke uppercase
        $code = strtoupper(trim($request->code));
        if (strlen($code) === 4) {
            $code = 'PJKR-' . $code;
        }
        $classroom = Classroom::where('code', $code)->first();

        if (!$classroom) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode kelas tidak valid atau tidak ditemukan.'
            ], 404);
        }

        $user = Auth::user();
        $promotedMessage = null;

        // [SMART GAMIFIED PROGRESSION: Opsi A + B]
        // Jika mahasiswa memasukkan token kelas di semester yang lebih tinggi dari semester aktifnya:
        if ($user->role === 'student' && $user->semester && (int)$classroom->semester > (int)$user->semester) {
            $currentSem = (int) $user->semester;
            $targetSem = (int) $classroom->semester;

            // Cegah loncat lebih dari 1 semester sekaligus (misal dari Sem 2 langsung ke Sem 7)
            if ($targetSem > $currentSem + 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Kenaikan semester harus berurutan. Anda saat ini di Semester {$currentSem}, silakan selesaikan Semester " . ($currentSem + 1) . " terlebih dahulu atau hubungi Dosen Anda."
                ], 403);
            }

            // Cek apakah seluruh pertemuan pada kelas yang diikuti di semester aktif sudah tuntas 100%
            $currentSemClassrooms = $user->classrooms()
                ->where('semester', (string) $currentSem)
                ->with('course.levels')
                ->get();

            $requiredLevelIds = $currentSemClassrooms
                ->pluck('course')
                ->filter()
                ->flatMap(function ($course) {
                    return $course->levels ? $course->levels->pluck('id') : [];
                })
                ->unique()
                ->values()
                ->toArray();

            $totalRequired = count($requiredLevelIds);
            $completedRequired = $totalRequired > 0
                ? \App\Models\UserProgress::where('user_id', $user->id)
                    ->whereIn('level_id', $requiredLevelIds)
                    ->where('is_completed', true)
                    ->count()
                : 0;

            if ($totalRequired === 0 || $completedRequired < $totalRequired) {
                $pct = $totalRequired > 0 ? round(($completedRequired / $totalRequired) * 100) : 0;
                return response()->json([
                    'status' => 'error',
                    'message' => $totalRequired === 0
                        ? "Anda belum menyelesaikan kelas apa pun di Semester {$currentSem}. Tuntaskan perkuliahan Semester {$currentSem} terlebih dahulu atau hubungi Dosen untuk menaikkan semester Anda."
                        : "Belum dapat naik ke Semester {$targetSem}. Selesaikan 100% materi & tugas pada kelas Semester {$currentSem} Anda terlebih dahulu (Progres saat ini: {$completedRequired}/{$totalRequired} pertemuan • {$pct}%)."
                ], 403);
            }

            $promotedMessage = "Selamat! Seluruh materi Semester {$currentSem} telah tuntas 100%. Anda otomatis naik ke Semester {$targetSem} dan bergabung ke kelas {$classroom->name}!";
        }

        $user->classroom_id = $classroom->id;
        
        // Hanya update semester jika kelas baru berada di semester yang lebih tinggi 
        // (mencegah penurunan semester saat mengambil kelas mengulang di bawahnya)
        if (!$user->semester || (int)$classroom->semester > (int)$user->semester) {
            $user->semester = $classroom->semester;
        }
        
        $user->save();

        // Gabungkan kelas baru di tabel pivot tanpa menghapus kelas lama
        $user->classrooms()->syncWithoutDetaching([$classroom->id]);

        // Load ulang data user dengan relasi
        $user->loadMissing(['achievements', 'progress', 'classroom', 'classrooms.course']);

        // Format data respons menyerupai profil terotentikasi
        $rankQuery = User::where('role', 'student')->where('classroom_id', $classroom->id);
        $rank = $rankQuery->where(function($q) use ($user) {
            $q->where('points', '>', $user->points)
              ->orWhere(function($q2) use ($user) {
                  $q2->where('points', $user->points)
                     ->where('level', '>', $user->level);
              });
        })->count() + 1;

        $completedLevelIds = \App\Models\UserProgress::where('user_id', $user->id)
            ->where('is_completed', true)
            ->pluck('level_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        $userData = $user->toArray();
        $userData['completed_level_ids'] = $completedLevelIds;
        $userData['rank'] = $rank;

        return response()->json([
            'status' => 'success',
            'message' => $promotedMessage ?? ('Berhasil bergabung ke kelas ' . $classroom->name . '!'),
            'user' => $userData
        ]);
    }

    /**
     * [PUBLIK] Memverifikasi kode kelas saat registrasi.
     */
    public function verifyCode($code)
    {
        $code = strtoupper(trim($code));
        if (strlen($code) === 4) {
            $code = 'PJKR-' . $code;
        }

        $classroom = Classroom::with('course')->where('code', $code)->first();

        if (!$classroom) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode kelas tidak terdaftar.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'classroom' => [
                'id' => $classroom->id,
                'name' => $classroom->name,
                'code' => $classroom->code,
                'semester' => $classroom->semester,
                'course_title' => $classroom->course ? $classroom->course->title : 'Mata Kuliah Umum',
            ]
        ]);
    }
}
