<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserProgress;
use App\Models\Achievement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Registrasi Mahasiswa Baru (PJKR Universitas Malang)
     */
    public function register(Request $request)
    {
        $request->validate([
            'nim' => 'required|string|unique:users',
            'name' => 'required|string',
            'phone' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8|confirmed',
            'classroom_code' => 'nullable|string',
        ], [
            'nim.unique' => 'NIM ini sudah terdaftar di sistem. Silakan login.',
            'email.unique' => 'Email ini sudah digunakan oleh akun lain.',
            'nim.required' => 'NIM wajib diisi.',
            'name.required' => 'Nama lengkap wajib diisi.',
            'phone.required' => 'Nomor WhatsApp wajib diisi.',
            'email.required' => 'Alamat email wajib diisi.',
            'password.required' => 'Password wajib diisi.',
            'password.min' => 'Password minimal harus 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ]);

        $classroom = null;
        if ($request->filled('classroom_code')) {
            $code = strtoupper(trim($request->classroom_code));
            if (strlen($code) === 4) {
                $code = 'PJKR-' . $code;
            }
            $classroom = \App\Models\Classroom::where('code', $code)->first();

            if (!$classroom) {
                throw ValidationException::withMessages([
                    'classroom_code' => ['Kode kelas tidak valid atau tidak ditemukan. Hubungi dosen Anda.'],
                ]);
            }
        }

        $user = User::create([
            'nim' => $request->nim,
            'name' => $request->name,
            'semester' => $classroom ? $classroom->semester : null,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'student',
            'points' => 0,
            'level' => 1,
            'classroom_id' => $classroom ? $classroom->id : null,
        ]);

        if ($classroom) {
            $user->classrooms()->attach($classroom->id);
        }

        // Berikan Achievement "Rookie" segera setelah registrasi
        $rookieAchievement = Achievement::where('name', 'Rookie')->first();
        if ($rookieAchievement) {
            $user->achievements()->attach($rookieAchievement->id, ['earned_at' => now()]);
        }

        return response()->json([
            'message' => 'Registrasi berhasil',
            'user' => $user,
            'token' => $user->createToken('auth_token')->plainTextToken,
        ], 201);
    }

    /**
     * Login menggunakan NIM Mahasiswa
     */
    public function login(Request $request)
    {
        $request->validate([
            'nim' => 'required|string', 
            'password' => 'required',
        ]);

        $user = User::where('nim', $request->nim)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'nim' => ['NIM atau Password yang Anda masukkan salah.'],
            ]);
        }

        // Update Streak
        $user->updateStreak();

        // Ambil data lengkap agar frontend tidak 'pental' karena data tidak lengkap
        $fullUserData = $this->formatUserData($user);

        return response()->json([
            'message' => 'Login berhasil',
            'user' => $fullUserData,
            'token' => $user->createToken('auth_token')->plainTextToken,
        ]);
    }

    /**
     * Endpoint Profil Utama (Untuk Dashboard & State Global)
     */
    public function profile(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User tidak ditemukan'], 401);
            }

            // Update Streak saat akses profil
            $user->updateStreak();

            $fullUserData = $this->formatUserData($user);

            return response()->json([
                'status' => 'success',
                'data' => $fullUserData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memuat profil: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update Profil Mahasiswa (Email, Phone, Avatar, Password)
     */
    public function updateProfile(\App\Http\Requests\UpdateProfileRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        // 1. Update Data Dasar
        if ($request->has('email')) {
            $user->email = $data['email'];
        }
        if ($request->has('phone')) {
            $user->phone = $data['phone'];
        }
        if ($request->has('bio')) {
            $user->bio = $data['bio'];
        }

        // 2. Handle Ganti Password
        if ($request->has('password') && $request->filled('password')) {
            if (!Hash::check($data['current_password'], $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Password saat ini tidak cocok.'
                ], 422);
            }
            $user->password = Hash::make($data['password']);
        }

        // 3. Handle Avatar Upload
        if ($request->hasFile('avatar')) {
            // Hapus avatar lama jika ada
            if ($user->avatar && str_contains($user->avatar, 'storage/')) {
                $oldPath = str_replace(asset(''), '', $user->avatar);
                \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = asset('storage/' . $path);
        }

        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Profil berhasil diperbarui.',
            'data' => $this->formatUserData($user)
        ]);
    }

    /**
     * Helper untuk menyeragamkan struktur data User + Progress + Achievements
     */
    private function formatUserData($user)
    {
        try {
            // Update Streak saat akses profil (Sudah dipanggil di atas, tapi pastikan konsisten)
            $user->updateStreak();

            // CEK ACHIEVEMENT: Konsistensi Tinggi (7 Day Streak)
            if ($user->current_streak >= 7) {
                $streakAchievement = Achievement::where('name', 'Konsistensi Tinggi')->first();
                if ($streakAchievement && !$user->achievements()->where('achievement_id', $streakAchievement->id)->exists()) {
                    $user->achievements()->attach($streakAchievement->id, ['earned_at' => now()]);
                }
            }

            // Memuat relasi secara opsional untuk menghindari crash jika relasi belum didefinisikan sempurna
            $userData = $user->loadMissing(['achievements', 'progress', 'classroom', 'classrooms']);
            
            // Hitung peringkat (Rank) mahasiswa berdasarkan poin & level (Per Kelas/Classroom jika Mahasiswa)
            $rankQuery = User::where('role', 'student');
            
            if ($user->role === 'student' && $user->classroom_id) {
                $rankQuery->where('classroom_id', $user->classroom_id);
            }

            $rank = $rankQuery->where(function($q) use ($user) {
                $q->where('points', '>', $user->points)
                  ->orWhere(function($q2) use ($user) {
                      $q2->where('points', $user->points)
                         ->where('level', '>', $user->level);
                  });
            })->count() + 1;

            // CEK ACHIEVEMENT: Ranking 1 Leaderboard
            if ($rank === 1 && $user->role === 'student') {
                $rankAchievement = Achievement::where('name', 'Ranking 1 Leaderboard')->first();
                if ($rankAchievement && !$user->achievements()->where('achievement_id', $rankAchievement->id)->exists()) {
                    $user->achievements()->attach($rankAchievement->id, ['earned_at' => now()]);
                }
            }

            // CEK ACHIEVEMENT: Kolektor Lencana (Mendapatkan 7 lencana)
            if ($user->achievements()->count() >= 7) {
                $collectorAchievement = Achievement::where('name', 'Kolektor Lencana')->first();
                if ($collectorAchievement && !$user->achievements()->where('achievement_id', $collectorAchievement->id)->exists()) {
                    $user->achievements()->attach($collectorAchievement->id, ['earned_at' => now()]);
                }
            }

            $completedLevelIds = UserProgress::where('user_id', $user->id)
                ->where('is_completed', true)
                ->pluck('level_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();

            $responseData = $userData->toArray();
            $responseData['completed_level_ids'] = $completedLevelIds;
            $responseData['rank'] = $rank;

            return $responseData;
        } catch (\Exception $e) {
            // Fallback jika load relasi gagal, kirim data dasar user saja
            $responseData = $user->toArray();
            $responseData['completed_level_ids'] = [];
            $responseData['error_debug'] = $e->getMessage();
            return $responseData;
        }
    }

    /**
     * Logout dan menghapus token akses
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}