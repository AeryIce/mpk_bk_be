<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $resp = $next($request);

        // Basic hardening untuk API
        $resp->headers->set('X-Content-Type-Options', 'nosniff');
        $resp->headers->set('Referrer-Policy', 'no-referrer');
        $resp->headers->set('X-Frame-Options', 'DENY');

        // CSP minimal untuk API (tidak render konten)
        $resp->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        // HSTS hanya di production + HTTPS
        if (app()->isProduction() && $request->isSecure()) {
            $resp->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $resp;
    }
}
