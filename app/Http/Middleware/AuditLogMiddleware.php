<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AuditLogMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Hanya catat aktivitas penting (POST, PUT, DELETE) dan request yang sukses
        if (Auth::check() && in_array($request->method(), ['POST', 'PUT', 'DELETE']) && $response->getStatusCode() < 400) {
            
            $action = $this->determineAction($request);
            
            if ($action) {
                DB::table('activity_logs')->insert([
                    'user_id' => Auth::id(),
                    'action' => $action,
                    'payload' => json_encode($request->except(['password', 'password_confirmation', 'avatar'])),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $response;
    }

    private function determineAction(Request $request)
    {
        $path = $request->path();
        
        if (str_contains($path, 'complete')) return 'complete_level';
        if (str_contains($path, 'login')) return 'login';
        if (str_contains($path, 'register')) return 'register';
        if (str_contains($path, 'grade')) return 'grade_assignment';
        if (str_contains($path, 'settings')) return 'update_settings';
        
        return 'api_action: ' . $path;
    }
}
