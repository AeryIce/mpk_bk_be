<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!filter_var(env('REQUEST_LOGGER', false), FILTER_VALIDATE_BOOL)) {
            return $next($request);
        }

        $start = microtime(true);
        /** @var Response $response */
        $response = $next($request);
        $ms = round((microtime(true) - $start) * 1000, 1);

        // Hati-hati: jangan log data sensitif/body
        Log::info('HTTP', [
            'ip'     => $request->ip(),
            'method' => $request->method(),
            'path'   => '/'.$request->path(),
            'status' => $response->getStatusCode(),
            'ms'     => $ms,
            'ua'     => substr($request->userAgent() ?? '-', 0, 120),
        ]);

        return $response;
    }
}
