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
        
        // 🔐 រក្សាទុក Alias សម្រាប់ផ្ទាំង Admin ដដែល (កូដដើមរបស់បង)
        $middleware->alias([
            'admin.token' => \App\Http\Middleware\EnsureAdminApiToken::class,
        ]);

        // 🎯 ដំណោះស្រាយ៖ លើកលែងច្បាប់ CSRF សម្រាប់រាល់ API និង Webhook ទាំងអស់ (រួមទាំងចាស់ និងថ្មី)
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'api/khqr-webhook',
            'api/khqr/webhook',
            'api/flashtopup/webhook',
        ]);

        // 🌐 ចុះឈ្មោះហៅប្រើប្រាស់ CorsMiddleware ដែលយើងទើបបង្កើតអម្បាញ់មិញ (បំបាត់ CORS Error)
        $middleware->append(\App\Http\Middleware\CorsMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();