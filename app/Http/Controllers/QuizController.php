<?php

namespace App\Http\Controllers;

use App\Models\Level;
use App\Models\Question;
use App\Models\Option;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuizController extends Controller
{
    /**
     * Mengambil daftar pertanyaan untuk satu pertemuan (level).
     */
    public function index($level_id)
    {
        $questions = Question::with('options')
            ->where('level_id', $level_id)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $questions
        ]);
    }

    /**
     * Menyimpan satu pertanyaan kuis beserta pilihan jawabannya.
     * Google Forms-style: Admin mengirim teks pertanyaan + array pilihan.
     */
    public function store(Request $request, $level_id)
    {
        try {
            $request->validate([
                'text' => 'required|string',
                'points' => 'required|integer',
                'options' => 'required|array|min:2',
                'options.*.text' => 'required|string',
                'options.*.is_correct' => 'required|boolean',
            ]);

            return DB::transaction(function () use ($request, $level_id) {
                // 1. Buat Pertanyaan
                $question = Question::create([
                    'level_id' => $level_id,
                    'text' => $request->text,
                    'points' => $request->points
                ]);

                // 2. Buat Pilihan Jawaban
                foreach ($request->options as $opt) {
                    $question->options()->create([
                        'text' => $opt['text'],
                        'is_correct' => $opt['is_correct']
                    ]);
                }

                return response()->json([
                    'message' => 'Pertanyaan berhasil disimpan!',
                    'data' => $question->load('options')
                ], 201);
            });

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menyimpan pertanyaan', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Memperbarui satu pertanyaan kuis.
     */
    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'text' => 'required|string',
                'points' => 'required|integer',
                'options' => 'required|array|min:2',
                'options.*.text' => 'required|string',
                'options.*.is_correct' => 'required|boolean',
            ]);

            $question = Question::findOrFail($id);

            return DB::transaction(function () use ($request, $question) {
                // 1. Update Pertanyaan
                $question->update([
                    'text' => $request->text,
                    'points' => $request->points
                ]);

                // 2. Refresh Pilihan Jawaban (Hapus lama, buat baru)
                $question->options()->delete();
                foreach ($request->options as $opt) {
                    $question->options()->create([
                        'text' => $opt['text'],
                        'is_correct' => $opt['is_correct']
                    ]);
                }

                return response()->json([
                    'message' => 'Pertanyaan berhasil diperbarui!',
                    'data' => $question->load('options')
                ]);
            });

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memperbarui pertanyaan', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Menghapus satu pertanyaan kuis.
     */
    public function destroy($id)
    {
        try {
            $question = Question::findOrFail($id);
            $question->delete();
            return response()->json(['message' => 'Pertanyaan berhasil dihapus']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menghapus pertanyaan'], 500);
        }
    }
}
