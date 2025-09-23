<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('magiclink-email', function (Request $request) {
        $email = strtolower((string) $request->input('email'));
        $key = $email !== '' ? $email : $request->ip();

        return [
            Limit::perMinute(3)->by($key), // max 3x per menit per email
            Limit::perHour(20)->by($key),  // dan max 20x per jam per email
        ];
    });
    }
}
