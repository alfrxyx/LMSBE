<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\IsTeacherOrAdmin;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        
        // Register Middleware Alias
        $middleware->alias([
            'isAdmin' => IsAdmin::class, 
            'isTeacherOrAdmin' => IsTeacherOrAdmin::class,
        ]);
        // ^ ^ ^ TAMBAHKAN BLOK ALIAS INI ^ ^ ^

        // Grup middleware untuk API
        $middleware->group('api', [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:300,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();