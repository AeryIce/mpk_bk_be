<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;

// ADD imports for rate limiting
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; 

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        //
    ])
    ->withMiddleware(function (Middleware $middleware) {
        /**
         * ======== S-3: RATE LIMITERS ========
         * Note: definisikan DI SINI supaya alias throttle:magiclink-* dikenal.
         */

        // 3.1 — limiter untuk REQUEST magic link (gabungan email+IP)
        RateLimiter::for('magiclink-email', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));
            $ip    = (string) $request->ip();
            $key   = 'ml:req:' . sha1($email . '|' . $ip);

            return [
                tap(Limit::perMinute(3)->by($key))->response(function ($request, array $headers) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'too_many_requests_minute',
                        'message' => 'Too many requests. Please wait a moment.',
                    ], 429, $headers);
                }),
                tap(Limit::perHour(20)->by($key))->response(function ($request, array $headers) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'too_many_requests_hour',
                        'message' => 'Too many requests in an hour. Please try again later.',
                    ], 429, $headers);
                }),
            ];
        });

        // 3.2 — limiter untuk CONSUME (berbasis IP)
        RateLimiter::for('magiclink-consume', function (Request $request) {
            $key = 'ml:consume:' . (string) $request->ip();

            return [
                tap(Limit::perMinute(10)->by($key))->response(function ($request, array $headers) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'too_many_consume',
                        'message' => 'Too many consume attempts. Please slow down.',
                    ], 429, $headers);
                }),
                tap(Limit::perHour(100)->by($key))->response(function ($request, array $headers) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'too_many_consume_hour',
                        'message' => 'Too many consume attempts this hour.',
                    ], 429, $headers);
                }),
            ];
        });
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
                Log::error('[API][500] '.$e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                error_log('[API][500] '.$e->getMessage().' {"file":"'.$e->getFile().'","line":'.$e->getLine().'}');
                return response()->json(['ok' => false, 'error' => 'server_error'], 500);
            }
        });
    })
    ->create();
