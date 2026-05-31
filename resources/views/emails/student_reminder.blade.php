<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px; }
        .header { background-color: #2563eb; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
        .content { padding: 20px; }
        .footer { text-align: center; font-size: 12px; color: #999; margin-top: 20px; }
        .button { display: inline-block; padding: 12px 25px; background-color: #2563eb; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>PJKR UM Educator Portal</h1>
        </div>
        <div class="content">
            <p>Halo <strong>{{ $user->name }}</strong>,</p>
            
            @if($type === 'manual')
                <p>Dosen pengampu Anda memberikan pengingat bahwa Anda belum menyelesaikan materi/tugas berikut:</p>
            @else
                <p>Sistem mendeteksi bahwa batas waktu (deadline) untuk materi/tugas berikut adalah <strong>BESOK</strong>:</p>
            @endif

            <div style="background-color: #f8fafc; padding: 15px; border-left: 4px solid #2563eb; margin: 20px 0;">
                <h3 style="margin: 0;">{{ $level->title }}</h3>
                <p style="margin: 5px 0 0 0; font-size: 14px; color: #64748b;">Mata Kuliah: {{ $level->course->title }}</p>
                @if($level->deadline)
                    <p style="margin: 5px 0 0 0; font-size: 14px; color: #ef4444;"><strong>Deadline: {{ $level->deadline->format('d M Y, H:i') }}</strong></p>
                @endif
            </div>

            <p>Segera selesaikan materi ini untuk mendapatkan <strong>{{ $level->xp_reward }} XP</strong> dan menjaga progres belajar Anda tetap optimal.</p>

            <center>
                <a href="{{ config('app.url') }}/courses/{{ $level->course_id }}" class="button">Buka Platform Sekarang</a>
            </center>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} PJKR Universitas Negeri Malang. Semua hak dilindungi.</p>
        </div>
    </div>
</body>
</html>
