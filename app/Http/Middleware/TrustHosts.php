<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Domain yang diizinkan. Gunakan pola RegExp tanpa delimiter.
     */
    public function hosts(): array
    {
        return [
            'mpkbkbe-production\.up\.railway\.app', // domain prod kamu
            'localhost',
            '127\.0\.0\.1',
        ];
    }
}
