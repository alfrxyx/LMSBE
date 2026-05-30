<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsTeacherOrAdmin
{
    /**
     * Handle an incoming request.
     * Mengizinkan akses hanya untuk Admin atau Dosen PJKR UM.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->role === 'admin' || $user->role === 'dosen')) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Akses ditolak. Halaman ini hanya untuk Dosen atau Admin.'
        ], 403);
    }
}
