<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Log;
use App\Http\Middleware\EnsurePatIsNotExpired;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Middleware\HandleCors;

// DAFTARKAN PROVIDER KITA
use App\Providers\AppServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        AppServiceProvider::class, // <— penting
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['pat.expires' => EnsurePatIsNotExpired::class,]);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 401 JSON untuk API
        $exceptions->renderable(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
            }
        });

        // Fallback 500 JSON + log
        $exceptions->renderable(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                Log::error('[API][500] '.$e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                error_log('[API][500] '.$e->getMessage().' {"file":"'.$e->getFile().'","line":'.$e->getLine().'}');
                return response()->json(['ok' => false, 'error' => 'server_error'], 500);
            }
        });
    })
    ->create();
