<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnvBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = filter_var(env('BASIC_AUTH_ENABLED', false), FILTER_VALIDATE_BOOL);
        if (!$enabled) abort(404);

        $reqUser = (string) $request->getUser();
        $reqPass = (string) $request->getPassword();

        $envUser = (string) env('BASIC_AUTH_USER', '');
        $envPass = (string) env('BASIC_AUTH_PASS', '');

        if ($envUser === '' || $envPass === '') return $this->reject();

        $okUser = hash_equals($envUser, $reqUser);
        $okPass = str_starts_with($envPass, '$2y$')
            ? password_verify($reqPass, $envPass)
            : hash_equals($envPass, $reqPass);

        if (!($okUser && $okPass)) return $this->reject();

        return $next($request);
    }

    private function reject(): Response
    {
        $r = new Response('Restricted', 401);
        $r->headers->set('WWW-Authenticate', 'Basic realm="BK Console"');
        return $r;
    }
}
