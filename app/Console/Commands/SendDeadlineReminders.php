<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Level;
use App\Models\User;
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
    protected $description = 'Kirim email pengingat H-1 deadline materi kepada mahasiswa yang belum selesai';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Memulai pengecekan deadline...");

        // Cari level yang deadlinenya besok
        $tomorrow = Carbon::tomorrow()->toDateString();
        
        $levels = Level::with('course')
            ->whereNotNull('deadline')
            ->whereDate('deadline', $tomorrow)
            ->get();

        if ($levels->isEmpty()) {
            $this->info("Tidak ada materi dengan deadline besok.");
            return;
        }

        foreach ($levels as $level) {
            $this->info("Memproses materi: {$level->title}");

            // Cari mahasiswa yang belum selesai di level ini
            $pendingUsers = User::where('role', 'student')
                ->whereDoesntHave('progress', function($q) use ($level) {
                    $q->where('level_id', $level->id)->where('is_completed', true);
                })->get();

            foreach ($pendingUsers as $user) {
                $this->info("- Mengirim email ke: {$user->email}");
                
                try {
                    Mail::to($user->email)->send(new StudentReminderMail($user, $level, 'auto'));
                } catch (\Exception $e) {
                    $this->error("  Gagal mengirim email ke {$user->email}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Selesai mengirim pengingat.");
    }
}
