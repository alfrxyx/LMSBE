<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Ambil semua setting publik (bisa diakses mahasiswa).
     */
    public function index()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'dashboard_banner_text' => Setting::get('dashboard_banner_text', 'Jangan lupa untuk mengerjakan Courses yang ada di hari ini'),
            ]
        ]);
    }

    /**
     * Update setting (Hanya Admin/Dosen).
     */
    public function update(Request $request)
    {
        $request->validate([
            'settings' => 'required|array',
        ]);

        foreach ($request->settings as $key => $value) {
            Setting::set($key, $value);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pengaturan berhasil diperbarui'
        ]);
    }
}
