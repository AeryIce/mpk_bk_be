<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Limit request email (POST /auth/magic-link/request)
        // Kunci by email+IP. Default: 3 per menit.
        RateLimiter::for('magiclink-email', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));
            $ip    = $request->ip();
            $key   = 'ml:req:' . sha1($email.'|'.$ip);

            $perMin = (int) env('RL_MAGIC_EMAIL_PER_MIN', 3);

            return Limit::perMinute($perMin)->by($key)->response(function () use ($perMin) {
                return response()->json([
                    'ok'      => false,
                    'error'   => 'too_many_requests',
                    'message' => "Too many magic link requests. Try again later.",
                ], 429);
            });
        });

        // Limit consume (POST/GET /auth/magic-link/consume)
        // Kunci by IP. Default: 10 per menit.
        RateLimiter::for('magiclink-consume', function (Request $request) {
            $ip     = $request->ip();
            $perMin = (int) env('RL_MAGIC_CONSUME_PER_MIN', 10);

            return Limit::perMinute($perMin)->by('ml:consume:' . $ip)->response(function () {
                return response()->json([
                    'ok'    => false,
                    'error' => 'too_many_requests',
                ], 429);
            });
        });

        // (Opsional) limiter untuk log viewer, kunci by IP. Default 60/min.
        RateLimiter::for('admin-logs', function (Request $request) {
            $perMin = (int) env('RL_ADMIN_LOGS_PER_MIN', 60);
            return Limit::perMinute($perMin)->by('logs:' . $request->ip());
        });
    }
}
