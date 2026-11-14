<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Level;
use App\Models\User;
use App\Models\Notification;
use App\Mail\StudentReminderMail;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class SendDeadlineReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-deadline-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim notifikasi in-app & email pengingat H-1 deadline materi kepada mahasiswa yang belum selesai';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Memulai pengecekan deadline materi...");

        // Cari level yang deadlinenya besok (H-1)
        $tomorrow = Carbon::tomorrow()->toDateString();
        
        $levels = Level::with('course')
            ->whereNotNull('deadline')
            ->whereDate('deadline', $tomorrow)
            ->get();

        if ($levels->isEmpty()) {
            $this->info("Tidak ada materi dengan deadline besok ({$tomorrow}).");
            return;
        }

        foreach ($levels as $level) {
            $courseTitle = $level->course->title ?? 'Mata Kuliah PJKR';
            $this->info("Memproses materi: {$level->title} ({$courseTitle})");

            // Cari mahasiswa yang terdaftar di kelas mata kuliah ini namun belum menyelesaikan level ini
            // Mendukung relasi Many-to-Many (classrooms) maupun relasi langsung (classroom_id)
            $pendingUsers = User::where('role', 'student')
                ->where(function ($query) use ($level) {
                    $query->whereHas('classrooms', function ($q) use ($level) {
                        $q->where('classrooms.course_id', $level->course_id);
                    })
                    ->orWhereHas('classroom', function ($q) use ($level) {
                        $q->where('classrooms.course_id', $level->course_id);
                    });
                })
                ->whereDoesntHave('progress', function ($q) use ($level) {
                    $q->where('level_id', $level->id)->where('is_completed', true);
                })
                ->get();

            if ($pendingUsers->isEmpty()) {
                $this->info("  - Seluruh mahasiswa telah menuntaskan materi ini.");
                continue;
            }

            $deadlineFormatted = Carbon::parse($level->deadline)->translatedFormat('d F Y H:i');

            foreach ($pendingUsers as $user) {
                // Cegah spam jika perintah dijalankan ulang di hari yang sama
                $alreadyNotified = Notification::where('user_id', $user->id)
                    ->where('action_url', "/courses/{$level->course_id}")
                    ->whereDate('created_at', Carbon::today())
                    ->where('title', 'like', '%DEADLINE BESOK%')
                    ->exists();

                if ($alreadyNotified) {
                    $this->info("  - Lewati {$user->email} (sudah dikirimi pengingat hari ini).");
                    continue;
                }

                $this->info("  - Mengirim pengingat ke: {$user->name} ({$user->email})");

                // 1. Buat Notifikasi In-App (lonceng peringatan di antarmuka LMS)
                // Sekaligus memicu pengiriman Email HTML via Notification::booted()
                Notification::create([
                    'user_id' => $user->id,
                    'title' => "DEADLINE BESOK: {$level->title}",
                    'message' => "Pengingat: Batas pengumpulan tugas/materi \"{$level->title}\" pada mata kuliah {$courseTitle} adalah besok ({$deadlineFormatted} WIB). Segera selesaikan sebelum batas waktu berakhir!",
                    'type' => 'warning',
                    'action_url' => "/courses/{$level->course_id}",
                    'is_read' => false,
                ]);

                // 2. Kirim juga salinan email StudentReminderMail secara langsung
                try {
                    Mail::to($user->email)->send(new StudentReminderMail($user, $level, 'auto'));
                } catch (\Exception $e) {
                    $this->error("    Gagal mengirim email pengingat ke {$user->email}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Selesai memproses seluruh pengingat deadline.");
    }
}
