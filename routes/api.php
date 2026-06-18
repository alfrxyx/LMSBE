<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\LeaderboardController; 
use App\Http\Controllers\CourseController; 
use App\Http\Controllers\LevelController;
use App\Http\Controllers\Api\ProgressController; 
use App\Http\Controllers\AssignmentController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// =======================================================
// ROUTE PUBLIK (Akses Tanpa Token)
// =======================================================
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

// =======================================================
// ROUTE TERPROTEKSI (Memerlukan Token Sanctum)
// =======================================================
Route::middleware(['auth:sanctum', 'throttle:300,1'])->group(function () {
    
    Route::get('/leaderboard', [LeaderboardController::class, 'index']);
    Route::get('/courses', [CourseController::class, 'index']);
    
    // --- Data User & Profil ---
    // Rute dasar untuk validasi token awal
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Rute profil lengkap untuk Dashboard Mahasiswa PJKR UM
    // Pastikan ini memanggil AuthController@profile agar tidak Error 500
    Route::get('/user/profile', [AuthController::class, 'profile']);
    Route::post('/user/profile', [AuthController::class, 'updateProfile']);
    
    Route::post('/logout', [AuthController::class, 'logout']);

    // --- Lencana (Achievements) ---
    Route::get('/achievements', function () {
        return response()->json([
            'data' => \App\Models\Achievement::all()
        ]);
    });

    // --- Manajemen Mata Kuliah & Pertemuan ---
    Route::get('/courses/{id}', [CourseController::class, 'show']); 
    
    // Fitur Progresi Terkunci (Sequential Access)
    Route::get('/check-access/{level_id}', [ProgressController::class, 'checkAccess']);
    Route::get('/levels/{level_id}/quiz-history', [ProgressController::class, 'getQuizHistory']);
    
    // Fitur Pengumpulan Link YouTube & Penambahan XP
    Route::post('/levels/{id}/complete', [ProgressController::class, 'submitActivity'])->middleware('throttle:5,1');
    
    Route::get('/my-assignments', [AssignmentController::class, 'myAssignments']);

    Route::get('/announcements', [\App\Http\Controllers\AnnouncementController::class, 'index']);
    
    // --- Pengaturan Platform (Dinamis) ---
    Route::get('/settings', [\App\Http\Controllers\SettingController::class, 'index']);

    // =======================================================
    // ROUTE DOSEN & ADMIN (Otoritas PJKR UM)
    // =======================================================
    Route::middleware('isDosenOrAdmin')->group(function () {
        
        // Update Pengaturan Platform
        Route::post('/admin/settings', [\App\Http\Controllers\SettingController::class, 'update']);

        // Penilaian Tugas Mahasiswa
        Route::get('/dosen/assignments/pending', [AssignmentController::class, 'getPendingAssignments']);
        Route::get('/dosen/assignments/youtube', [AdminController::class, 'getYoutubeSubmissions']);
        Route::post('/dosen/assignments/{id}/grade', [AssignmentController::class, 'grade']);

        // Manajemen Konten Dinamis (PDF & Video)
        Route::post('/admin/courses', [AdminController::class, 'createCourse']);
        Route::put('/admin/courses/{id}', [AdminController::class, 'updateCourse']);
        Route::delete('/admin/courses/{id}', [AdminController::class, 'deleteCourse']);
        Route::post('/courses/{course_id}/levels', [LevelController::class, 'store']);
        Route::put('/levels/{id}', [LevelController::class, 'update']);
        Route::delete('/levels/{id}', [LevelController::class, 'destroy']);

        // --- QUIZ BUILDER (Google Forms-like) ---
        Route::get('/levels/{level_id}/questions', [\App\Http\Controllers\QuizController::class, 'index']);
        Route::post('/levels/{level_id}/questions', [\App\Http\Controllers\QuizController::class, 'store']);
        Route::put('/questions/{id}', [\App\Http\Controllers\QuizController::class, 'update']);
        Route::delete('/questions/{id}', [\App\Http\Controllers\QuizController::class, 'destroy']);

        Route::get('/admin/stats', [AdminController::class, 'getStats']);
        Route::get('/admin/users', [AdminController::class, 'indexUsers']);
        Route::get('/dosen/monitoring', [AdminController::class, 'getStudentMonitoring']);
        Route::get('/dosen/export-students', [AdminController::class, 'getStudentMonitoring']); // Alias untuk export

        // --- FITUR REMINDER & STATISTIK MATERI ---
        Route::get('/dosen/levels/{level_id}/stats', [AdminController::class, 'getMaterialStats']);
        Route::post('/dosen/remind-student', [AdminController::class, 'remindStudent']);

        // --- PENGUMUMAN MASAL ---
        Route::post('/announcements', [\App\Http\Controllers\AnnouncementController::class, 'store']);
        Route::delete('/announcements/{id}', [\App\Http\Controllers\AnnouncementController::class, 'destroy']);
    });

    // =======================================================
    // ROUTE NOTIFIKASI
    // =======================================================
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy']);

});