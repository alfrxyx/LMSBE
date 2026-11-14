<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use App\Models\User;
use App\Mail\ResetPasswordMail;

class ForgotPasswordController extends Controller
{
    /**
     * Kirim email berisi tautan reset password.
     * Menerima input berupa Email atau NIM mahasiswa/dosen.
     */
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
        ], [
            'identifier.required' => 'NIM atau Alamat Email wajib diisi.',
        ]);

        $identifier = trim($request->identifier);

        // Cari pengguna berdasarkan email atau NIM
        $user = User::where('email', $identifier)
            ->orWhere('nim', $identifier)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'identifier' => ['Akun dengan NIM atau Email tersebut tidak terdaftar di sistem kami.'],
            ]);
        }

        // Buat token acak yang aman
        $plainToken = Str::random(64);

        // Simpan token ke tabel password_reset_tokens
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($plainToken),
                'created_at' => Carbon::now(),
            ]
        );

        // Tentukan base URL frontend
        $frontendUrl = env('FRONTEND_URL');
        if (! $frontendUrl) {
            $frontendUrl = config('app.url', 'https://pjkrlearn.my.id');
        }
        $frontendUrl = rtrim($frontendUrl, '/');

        $resetUrl = "{$frontendUrl}/reset-password?token={$plainToken}&email=" . urlencode($user->email);

        // Kirim email langsung
        try {
            Mail::to($user->email)->send(new ResetPasswordMail($user, $resetUrl));
        } catch (\Exception $e) {
            \Log::error('Gagal mengirim email reset password: ' . $e->getMessage());
            throw ValidationException::withMessages([
                'identifier' => ['Gagal mengirim email. Pastikan koneksi mail server aktif atau hubungi administrator.'],
            ]);
        }

        // Samarkan email untuk privasi (contoh: al***@gmail.com)
        $emailParts = explode('@', $user->email);
        $namePart = $emailParts[0];
        $domainPart = $emailParts[1] ?? '';
        $maskedName = substr($namePart, 0, 2) . str_repeat('*', max(3, strlen($namePart) - 2));
        $maskedEmail = "{$maskedName}@{$domainPart}";

        return response()->json([
            'message' => "Tautan reset kata sandi telah dikirim ke email terdaftar Anda ({$maskedEmail}). Silakan periksa kotak masuk atau spam email Anda.",
            'email' => $maskedEmail,
        ]);
    }

    /**
     * Eksekusi reset password baru dengan verifikasi token.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|min:8|confirmed',
        ], [
            'email.required' => 'Email wajib disertakan.',
            'email.email' => 'Format email tidak valid.',
            'token.required' => 'Token reset kata sandi tidak ditemukan.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.min' => 'Kata sandi minimal harus 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'token' => ['Permintaan reset kata sandi tidak ditemukan atau sudah pernah digunakan.'],
            ]);
        }

        // Cek apakah token sudah kadaluarsa (lebih dari 60 menit)
        $createdAt = Carbon::parse($record->created_at);
        if ($createdAt->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            throw ValidationException::withMessages([
                'token' => ['Tautan reset kata sandi telah kadaluarsa (lebih dari 60 menit). Silakan ajukan permintaan ulang.'],
            ]);
        }

        // Verifikasi kecocokan token
        if (! Hash::check($request->token, $record->token)) {
            throw ValidationException::withMessages([
                'token' => ['Token reset kata sandi tidak valid atau telah dirusak.'],
            ]);
        }

        // Cari user dan perbarui password
        $user = User::where('email', $request->email)->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Pengguna dengan email ini tidak ditemukan.'],
            ]);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Hapus token yang sudah terpakai
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // Cabut token API aktif agar pengguna login ulang dengan kredensial baru
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Kata sandi Anda berhasil diperbarui! Silakan masuk menggunakan kata sandi baru Anda.',
        ]);
    }
}
