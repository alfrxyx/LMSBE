<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\IsDosenOrAdmin;

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
            'isDosenOrAdmin' => IsDosenOrAdmin::class,
        ]);
        // ^ ^ ^ TAMBAHKAN BLOK ALIAS INI ^ ^ ^

        // Grup middleware untuk API
        $middleware->group('api', [
            'throttle:300,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\AuditLogMiddleware::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();