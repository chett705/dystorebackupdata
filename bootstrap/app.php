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

        // 🎯 ដំណោះស្រាយ៖ លើកលែងច្បាប់ CSRF ផ្លូវលីង Webhook របស់ធនាគារ (បិទការឆែក Token ត្រង់ផ្លូវនេះ)
        $middleware->validateCsrfTokens(except: [
            'api/khqr/webhook',
            'api/flashtopup/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();