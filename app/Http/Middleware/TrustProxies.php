<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Percaya semua reverse proxies (Railway pakai proxy di depan app).
     */
    protected $proxies = '*';

    /**
     * Baca header X-Forwarded-* yang dipakai oleh Railway.
     * (Konstanta HEADER_X_FORWARDED_ALL sudah dihapus.)
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR  |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;
        // kalau perlu prefix: tambahkan ->  | Request::HEADER_X_FORWARDED_PREFIX;
}
