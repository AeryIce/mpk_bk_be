<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Jangan redirect tamu ke route('login') (karena kita pakai API + magic link)
        $middleware->redirectGuestsTo(fn () => null);

        // Alias middleware (pakai FQCN biar Intelephense tidak komplain)
        $middleware->alias([
            'role'         => \App\Http\Middleware\EnsureRole::class,
            'pat.expires'  => \App\Http\Middleware\EnsurePatIsNotExpired::class,
        ]);

        // Global middlewares
        $middleware->append(HandleCors::class);                       // aktifkan CORS (config/cors.php)
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class); // header keamanan dasar
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Request API tanpa bearer -> 401 JSON (bukan redirect ke /login)
        $exceptions->renderable(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
            }
        });
    })
    ->create();
