<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;

use App\Http\Middleware\EnsurePatIsNotExpired;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // **PENTING**: Jangan redirect tamu ke route('login') (karena tidak ada)
        // Ini mencegah error "Route [login] not defined."
        $middleware->redirectGuestsTo(fn () => null);

        // Alias middleware kamu
        $middleware->alias([
            'pat.expires' => EnsurePatIsNotExpired::class,
        ]);

        // Global middlewares
        $middleware->append(HandleCors::class);     // aktifkan CORS (config/cors.php)
        $middleware->append(SecurityHeaders::class); // header keamanan OWASP baseline
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Pastikan request API tanpa bearer -> 401 JSON, bukan redirect/login
        $exceptions->renderable(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
            }
        });
    })
    ->create();
