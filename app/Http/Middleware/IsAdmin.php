<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth; // <-- Impor yang dibutuhkan

class IsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Cek apakah user sudah terautentikasi (melalui token Sanctum)
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthenticated. Token required.'], 401);
        }
        
        // 2. Cek ROLE pengguna
        if (Auth::user()->role !== 'admin') {
            // Jika role BUKAN admin, tolak akses dengan status 403 Forbidden
            return response()->json(['message' => 'Forbidden. You do not have administrator access.'], 403);
        }

        // 3. Jika lolos, lanjutkan request
        return $next($request);
    }
}