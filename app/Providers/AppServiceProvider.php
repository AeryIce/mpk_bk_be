<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
            // === Limiter: request magic link (gabungan email + IP) ===
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

            // === Limiter: consume magic link (berbasis IP) ===
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
    }
}
