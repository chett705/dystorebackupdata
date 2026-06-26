<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        
        // 🔐 រក្សាទុក Alias សម្រាប់ផ្ទាំង Admin ដដែល
        $middleware->alias([
            'admin.token' => \App\Http\Middleware\EnsureAdminApiToken::class,
        ]);

        // 🎯 លើកលែងច្បាប់ CSRF សម្រាប់រាល់ API និង Webhook ទាំងអស់
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'api/khqr-webhook',
            'api/khqr/webhook',
            'api/flashtopup/webhook',
        ]);

        // 🌐 បើកច្បាប់ CORS របៀបផ្លូវការរបស់ Laravel 11 ទៅកាន់ API ទាញទិន្នន័យ (មានសុវត្ថិភាពខ្ពស់ មិននាំឱ្យគាំង 502)
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();