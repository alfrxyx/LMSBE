<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'type',
        'action_url',
        'is_read'
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    /** Waktu pengiriman email terakhir (untuk menjaga jeda rate limit Resend). */
    protected static float $lastEmailAt = 0.0;

    /** Cache parsed .env file in-memory during request lifecycle */
    protected static ?array $cachedEnv = null;

    protected static function getLiveEnv(): array
    {
        if (self::$cachedEnv !== null) {
            return self::$cachedEnv;
        }

        $liveEnv = [];
        $envPath = base_path('.env');
        if (file_exists($envPath)) {
            try {
                $liveEnv = \Dotenv\Dotenv::parse(file_get_contents($envPath));
            } catch (\Throwable $e) {
                $liveEnv = [];
            }
        }
        self::$cachedEnv = $liveEnv;
        return self::$cachedEnv;
    }

    protected static function booted(): void
    {
        static::created(function (Notification $notification) {
            // Kirim email SETELAH respons HTTP dikirim ke browser agar dosen/mahasiswa tidak menunggu
            // (tanpa perlu menjalankan queue worker terpisah).
            dispatch(function () use ($notification) {
            try {
                $recipient = $notification->user()->first();
                if (!$recipient || empty($recipient->email) || !filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
                    return;
                }

                $frontendBaseUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
                $actionPath = $notification->action_url ? '/' . ltrim($notification->action_url, '/') : '/dashboard';
                $fullActionUrl = $frontendBaseUrl . $actionPath;

                $badgeColor = match ($notification->type) {
                    'success', 'grade' => '#059669',
                    'warning', 'reminder' => '#D97706',
                    default => '#EA580C',
                };

                $safeName = e($recipient->name);
                $safeTitle = e($notification->title);
                $safeMessage = nl2br(e($notification->message));

                $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>{$safeTitle}</title>
</head>
<body style="margin:0;padding:0;background-color:#F3F4F6;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#F3F4F6;padding:32px 16px;">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#FFFFFF;border-radius:20px;overflow:hidden;border:1px solid #E5E7EB;box-shadow:0 4px 12px rgba(0,0,0,0.05);">
          <!-- Header -->
          <tr>
            <td style="background:linear-gradient(135deg,#111827 0%,#1E293B 100%);padding:28px 32px;color:#FFFFFF;">
              <div style="font-size:11px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:#FBBF24;margin-bottom:6px;">
                GamifyLearn • LMS PJKR UM
              </div>
              <div style="font-size:20px;font-weight:800;line-height:1.3;color:#FFFFFF;">
                {$safeTitle}
              </div>
            </td>
          </tr>
          <!-- Body -->
          <tr>
            <td style="padding:32px;">
              <p style="margin:0 0 16px 0;font-size:14px;color:#374151;">
                Halo <strong>{$safeName}</strong>,
              </p>
              <div style="background-color:#F9FAFB;border-left:4px solid {$badgeColor};padding:16px 20px;border-radius:10px;font-size:14px;line-height:1.6;color:#1F2937;margin-bottom:24px;">
                {$safeMessage}
              </div>
              <table cellpadding="0" cellspacing="0" style="margin-top:8px;">
                <tr>
                  <td style="border-radius:12px;background-color:#EA580C;">
                    <a href="{$fullActionUrl}" target="_blank" style="display:inline-block;padding:12px 24px;font-size:12px;font-weight:800;color:#FFFFFF;text-decoration:none;text-transform:uppercase;letter-spacing:1px;">
                      Buka di Aplikasi GamifyLearn &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <!-- Footer -->
          <tr>
            <td style="padding:20px 32px;background-color:#F9FAFB;border-top:1px solid #F3F4F6;font-size:11px;color:#6B7280;text-align:center;">
              Email ini dikirim secara otomatis oleh Sistem Pembelajaran PJKR Fakultas Ilmu Keolahragaan Universitas Negeri Malang.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

                $liveEnv = self::getLiveEnv();

                $resendApiKey = trim($liveEnv['RESEND_API_KEY'] ?? env('RESEND_API_KEY', ''));
                $fromAddress = $liveEnv['MAIL_FROM_ADDRESS'] ?? env('MAIL_FROM_ADDRESS', 'noreply@pjkrlearn.my.id');
                $fromName = $liveEnv['MAIL_FROM_NAME'] ?? env('MAIL_FROM_NAME', 'GamifyLearn PJKR UM');
                $subjectLine = '[GamifyLearn PJKR] ' . $notification->title;

                // Prioritas 1: Kirim Real-Time via Resend API dengan domain resmi pjkrlearn.my.id
                if (!empty($resendApiKey) && str_starts_with($resendApiKey, 're_')) {
                    // Verifikasi sertifikat TLS aktif secara default. Matikan hanya di komputer lokal
                    // yang bermasalah CA bundle dengan RESEND_VERIFY_SSL=false di .env.
                    $verifySsl = filter_var($liveEnv['RESEND_VERIFY_SSL'] ?? true, FILTER_VALIDATE_BOOLEAN);

                    // Resend membatasi ~2 request/detik: beri jeda antar email
                    $gapMicro = 600000;
                    $elapsedMicro = (int) ((microtime(true) - self::$lastEmailAt) * 1000000);
                    if ($elapsedMicro < $gapMicro) {
                        usleep($gapMicro - $elapsedMicro);
                    }
                    self::$lastEmailAt = microtime(true);

                    $sendViaResend = function (string $from) use ($resendApiKey, $verifySsl, $recipient, $subjectLine, $htmlBody) {
                        $payload = [
                            'from' => $from,
                            'to' => [$recipient->email],
                            'subject' => $subjectLine,
                            'html' => $htmlBody,
                        ];
                        $response = Http::withOptions(['verify' => $verifySsl])
                            ->timeout(10)
                            ->withToken($resendApiKey)
                            ->post('https://api.resend.com/emails', $payload);

                        // Terkena rate limit: tunggu sebentar lalu coba sekali lagi
                        if ($response->status() === 429) {
                            sleep(1);
                            $response = Http::withOptions(['verify' => $verifySsl])
                                ->timeout(10)
                                ->withToken($resendApiKey)
                                ->post('https://api.resend.com/emails', $payload);
                        }
                        return $response;
                    };

                    if ($sendViaResend("{$fromName} <{$fromAddress}>")->successful()) {
                        return;
                    }

                    // Jika domain pjkrlearn.my.id masih proses propagasi DNS di Resend, otomatis fallback ke onboarding@resend.dev
                    if ($sendViaResend("{$fromName} <onboarding@resend.dev>")->successful()) {
                        return;
                    }
                }

                // Prioritas 2: Fallback via SMTP / Log standar Laravel
                $mailerName = $liveEnv['MAIL_MAILER'] ?? config('mail.default', 'log');
                $smtpHost = $liveEnv['MAIL_HOST'] ?? env('MAIL_HOST', 'smtp.gmail.com');
                $smtpPort = (int) ($liveEnv['MAIL_PORT'] ?? env('MAIL_PORT', 587));
                $smtpUsername = $liveEnv['MAIL_USERNAME'] ?? env('MAIL_USERNAME', '');
                $smtpPassword = str_replace(' ', '', $liveEnv['MAIL_PASSWORD'] ?? env('MAIL_PASSWORD', ''));

                if (
                    $mailerName === 'smtp' &&
                    (empty($smtpUsername) || $smtpUsername === 'email_anda@gmail.com' || $smtpUsername === 'null' ||
                     empty($smtpPassword) || $smtpPassword === 'xxxx_xxxx_xxxx_xxxx' || $smtpPassword === 'null')
                ) {
                    $mailerName = 'log';
                }

                if ($mailerName === 'smtp') {
                    config([
                        'mail.default' => 'smtp',
                        'mail.mailers.smtp.transport' => 'smtp',
                        'mail.mailers.smtp.host' => $smtpHost,
                        'mail.mailers.smtp.port' => $smtpPort,
                        'mail.mailers.smtp.username' => $smtpUsername,
                        'mail.mailers.smtp.password' => $smtpPassword,
                        'mail.from.address' => $fromAddress,
                        'mail.from.name' => $fromName,
                    ]);
                    Mail::purge('smtp');
                }

                Mail::mailer($mailerName)->html($htmlBody, function ($mail) use ($recipient, $subjectLine, $fromAddress, $fromName) {
                    if (!empty($fromAddress) && filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
                        $mail->from($fromAddress, $fromName);
                    }
                    $mail->to($recipient->email, $recipient->name)
                         ->subject($subjectLine);
                });
            } catch (\Throwable $e) {
                Log::warning('Gagal mengirim email notifikasi otomatis: ' . $e->getMessage());
            }
            })->afterResponse();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
