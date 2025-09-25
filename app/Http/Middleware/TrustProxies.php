<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Percaya semua reverse proxies (Railway pakai proxy di depan app).
     * Kalau mau lebih ketat, isi array alamat proxy spesifik.
     */
    protected $proxies = '*';

    /**
     * Baca seluruh header X-Forwarded-* untuk IP/HTTPS/Host.
     */
    protected $headers = Request::HEADER_X_FORWARDED_ALL;
}
