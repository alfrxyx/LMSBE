<?php

namespace App\Http\Controllers;

use App\Models\Level;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LevelController extends Controller
{
    /**
     * Menyimpan pertemuan (level) baru.
     * Mendukung upload file PDF materi.
     */
    public function store(Request $request, $course_id)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'xp_reward' => 'required|integer',
                'activity_type' => 'required|in:checklist,quiz,assignment,video',
                'youtube_id' => 'nullable|string',
                'order' => 'required|integer',
                'pdf' => 'nullable|mimes:pdf|max:10240', // Maksimal 10MB
            ]);

            $course = Course::findOrFail($course_id);

            // Handle PDF Upload
            if ($request->hasFile('pdf')) {
                $path = $request->file('pdf')->store('materi_pdf', 'public');
                $validated['pdf_path'] = $path;
            }

            $level = $course->levels()->create($validated);

            return response()->json([
                'message' => 'Pertemuan berhasil ditambahkan!',
                'data' => $level
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menambahkan pertemuan', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Memperbarui data pertemuan.
     */
    public function update(Request $request, $id)
    {
        try {
            $level = Level::findOrFail($id);
            
            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'description' => 'sometimes|required|string',
                'xp_reward' => 'sometimes|required|integer',
                'activity_type' => 'sometimes|required|in:checklist,quiz,assignment,video',
                'youtube_id' => 'nullable|string',
                'order' => 'sometimes|required|integer',
                'pdf' => 'nullable|mimes:pdf|max:10240',
            ]);

            if ($request->hasFile('pdf')) {
                // Hapus PDF lama jika ada
                if ($level->pdf_path) {
                    Storage::disk('public')->delete($level->pdf_path);
                }
                $path = $request->file('pdf')->store('materi_pdf', 'public');
                $validated['pdf_path'] = $path;
            }

            $level->update($validated);

            return response()->json(['message' => 'Pertemuan berhasil diperbarui!', 'data' => $level]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memperbarui pertemuan', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Menghapus pertemuan.
     */
    public function destroy($id)
    {
        try {
            $level = Level::findOrFail($id);
            if ($level->pdf_path) {
                Storage::disk('public')->delete($level->pdf_path);
            }
            $level->delete();
            return response()->json(['message' => 'Pertemuan berhasil dihapus']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menghapus pertemuan'], 500);
        }
    }
}
