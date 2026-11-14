<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #1e293b; background-color: #f1f5f9; padding: 20px 0; margin: 0; }
        .container { max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .header { background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #ffffff; padding: 30px 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.5px; }
        .header p { margin: 6px 0 0; font-size: 13px; opacity: 0.9; }
        .content { padding: 32px 28px; }
        .content p { margin: 0 0 16px; font-size: 14px; color: #334155; }
        .badge-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #2563eb; padding: 16px 20px; border-radius: 8px; margin: 20px 0; }
        .badge-box strong { color: #0f172a; }
        .button-wrap { text-align: center; margin: 30px 0; }
        .button { display: inline-block; padding: 14px 32px; background-color: #ea580c; color: #ffffff !important; text-decoration: none; border-radius: 10px; font-size: 14px; font-weight: 700; box-shadow: 0 4px 10px rgba(234, 88, 12, 0.3); }
        .note { font-size: 12px; color: #64748b; line-height: 1.5; margin-top: 24px; padding-top: 16px; border-top: 1px dashed #cbd5e1; }
        .footer { background-color: #f8fafc; text-align: center; font-size: 11px; color: #94a3b8; padding: 16px 20px; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>GamifyLearn PJKR UM</h1>
            <p>Platform Pembelajaran & Evaluasi Praktik PJKR Universitas Negeri Malang</p>
        </div>
        <div class="content">
            <p>Halo <strong>{{ $user->name }}</strong>,</p>
            <p>Kami menerima permintaan untuk mereset kata sandi akun GamifyLearn Anda yang terdaftar dengan identitas:</p>
            
            <div class="badge-box">
                <div style="font-size: 13px; color: #64748b;">NIM / ID: <strong style="color: #0f172a;">{{ $user->nim }}</strong></div>
                <div style="font-size: 13px; color: #64748b; margin-top: 4px;">Email: <strong style="color: #0f172a;">{{ $user->email }}</strong></div>
            </div>

            <p>Silakan klik tombol di bawah ini untuk membuat kata sandi baru. Tautan ini hanya berlaku selama <strong>60 menit</strong>:</p>

            <div class="button-wrap">
                <a href="{{ $resetUrl }}" target="_blank" class="button">Atur Ulang Kata Sandi Saya</a>
            </div>

            <p style="font-size: 12px; color: #64748b; word-break: break-all;">
                Jika tombol di atas tidak dapat diklik, salin dan tempel tautan berikut ke browser Anda:<br>
                <a href="{{ $resetUrl }}" style="color: #2563eb;">{{ $resetUrl }}</a>
            </p>

            <div class="note">
                <strong>Catatan Keamanan:</strong> Jika Anda tidak merasa melakukan permintaan reset kata sandi ini, abaikan email ini. Akun Anda tetap aman dan kata sandi Anda tidak akan berubah.
            </div>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Program Studi Pendidikan Jasmani dan Rekreasi - Universitas Negeri Malang.<br>Sistem Gamifikasi Pembelajaran Olahraga.</p>
        </div>
    </div>
</body>
</html>
