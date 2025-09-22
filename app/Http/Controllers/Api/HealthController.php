<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\App;

class HealthController extends Controller
{
    public function index()
    {
        $dbOk = false;
        try {
            DB::select('select 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbOk = false;
        }

        return response()->json([
            'ok' => true,
            'app' => Config::get('app.name'),
            'env' => App::environment(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'db_ok' => $dbOk,
            'time' => now()->toIso8601String(),
        ]);
    }
}
