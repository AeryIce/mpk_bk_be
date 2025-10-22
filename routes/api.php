<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\Admin\RegistrationAdminController;
use App\Http\Controllers\MasterController; // MasterController di App\Http\Controllers
use App\Models\Registration;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/health', [HealthController::class, 'index'])->name('health');

/**
 * AUTH MODULE (magic-link)
 */
Route::prefix('auth')->group(function () {
    Route::get('/ping', fn () => response()->json([
        'ok' => true,
        'module' => 'Auth',
        'time' => now()->toIso8601String(),
    ]))->name('auth.ping');

    // Rate limiter alias didefinisikan di AppServiceProvider
    Route::post('/magic-link/request', [MagicLinkController::class, 'request'])
        ->middleware('throttle:magiclink-email')
        ->name('auth.magiclink.request');

    Route::post('/magic-link/consume', [MagicLinkController::class, 'consume'])
        ->middleware('throttle:magiclink-consume')
        ->name('auth.magiclink.consume');

    // GET fallback agar token bisa diklik dari email
    Route::get('/magic-link/consume/{token}', function (string $token, Request $r) {
        $request = $r->merge(['token' => $token]);
        return app(MagicLinkController::class)->consume($request);
    })->middleware('throttle:magiclink-consume')
      ->name('auth.magiclink.consume.get');
});

/**
 * PROTECTED ROUTES (Sanctum Bearer + token-expiry check)
 */
Route::middleware(['auth:sanctum','pat.expires'])->get('/me', function (Request $request) {
    $u = $request->user();
    if (!$u) return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
    return response()->json([
        'ok' => true,
        'user' => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ],
    ]);
})->name('auth.me');

Route::middleware(['auth:sanctum','pat.expires'])->post('/logout', function (Request $request) {
    $token = $request->user()?->currentAccessToken();
    if ($token) $token->delete();
    return response()->json(['ok' => true]);
})->name('auth.logout');

/**
 * PROTECTED LOGS (Sanctum Bearer + ENV toggle + whitelist)
 */
Route::middleware('auth:sanctum')->prefix('admin/logs')->group(function () {

    // 0) Ping – cek enable & whitelist
    Route::get('/ping', function (Request $request) {
        $enabled = filter_var(env('LOG_VIEWER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $allowed = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_EMAILS', ''))));
        $email   = strtolower((string) optional($request->user())->email);
        $okEmail = $email && in_array($email, array_map('strtolower', $allowed), true);

        return response()->json([
            'ok' => $enabled && $okEmail,
            'enabled' => $enabled,
            'email' => $email,
            'whitelisted' => $okEmail,
        ], ($enabled && $okEmail) ? 200 : 403);
    })->name('admin.logs.ping');

    // 1) List file log
    Route::get('/', function (Request $request) {
        try {
            $enabled = filter_var(env('LOG_VIEWER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
            if (!$enabled) return response()->json(['ok' => false, 'error' => 'log_viewer_disabled'], 403);

            $allowed = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_EMAILS', ''))));
            $email   = strtolower((string) optional($request->user())->email);
            $okEmail = $email && in_array($email, array_map('strtolower', $allowed), true);
            if (!$okEmail) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);

            $base = storage_path('logs');
            if (!is_dir($base)) return response()->json(['ok' => true, 'path' => $base, 'files' => []]);

            $names = @scandir($base) ?: [];
            $files = [];
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') continue;
                if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) continue;
                $full = $base . DIRECTORY_SEPARATOR . $name;
                if (is_file($full)) {
                    $files[] = [
                        'name'        => $name,
                        'size_bytes'  => @filesize($full) ?: 0,
                        'modified_at' => date('c', @filemtime($full) ?: time()),
                    ];
                }
            }
            usort($files, fn($a, $b) => strcmp($b['modified_at'], $a['modified_at']));

            return response()->json(['ok' => true, 'path' => $base, 'files' => array_values($files)]);
        } catch (\Throwable $e) {
            Log::error('[LOGS][INDEX][500] '.$e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['ok' => false, 'error' => 'server_error'], 500);
        }
    })->name('admin.logs.index');

    // 2) View (path version)
    Route::get('/view/{file}', function (string $file, Request $request) {
        $enabled = filter_var(env('LOG_VIEWER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) return response()->json(['ok' => false, 'error' => 'log_viewer_disabled'], 403);

        $allowed = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_EMAILS', ''))));
        $email   = strtolower((string) optional($request->user())->email);
        $okEmail = $email && in_array($email, array_map('strtolower', $allowed), true);
        if (!$okEmail) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $file)) return response()->json(['ok' => false, 'error' => 'bad_filename'], 400);

        $base = realpath(storage_path('logs')) ?: storage_path('logs');
        $full = realpath($base . DIRECTORY_SEPARATOR . $file);
        if (!$full || strncmp($full, $base, strlen($base)) !== 0 || !is_file($full)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $bytes = (int) $request->query('bytes', 65536);
        $bytes = max(1024, min($bytes, 2 * 1024 * 1024));
        $size  = @filesize($full) ?: 0;
        $start = $size > $bytes ? $size - $bytes : 0;

        $fh = @fopen($full, 'rb');
        if ($fh === false) return response()->json(['ok' => false, 'error' => 'cannot_open'], 500);
        if ($start > 0) @fseek($fh, $start);
        $content = @stream_get_contents($fh) ?: '';
        @fclose($fh);

        if ($request->boolean('raw')) {
            return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response()->json([
            'ok'          => true,
            'file'        => basename($full),
            'size_bytes'  => $size,
            'start'       => $start,
            'end'         => $size,
            'bytes_read'  => strlen($content),
            'truncated'   => $start > 0,
            'modified_at' => date('c', @filemtime($full) ?: time()),
            'content'     => $content,
        ]);
    })->where('file', '[A-Za-z0-9._-]+')->name('admin.logs.view');

    // 3) View (query version) -> download
    Route::get('/view', function (Request $request) {
        $enabled = filter_var(env('LOG_VIEWER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) return response()->json(['ok' => false, 'error' => 'log_viewer_disabled'], 403);

        $allowed = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_EMAILS', ''))));
        $email   = strtolower((string) optional($request->user())->email);
        $okEmail = $email && in_array($email, array_map('strtolower', $allowed), true);
        if (!$okEmail) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);

        $file = (string) $request->query('file', '');
        if ($file === '') return response()->json(['ok' => false, 'error' => 'file_required'], 400);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $file)) return response()->json(['ok' => false, 'error' => 'bad_filename'], 400);

        $base = realpath(storage_path('logs')) ?: storage_path('logs');
        $full = realpath($base . DIRECTORY_SEPARATOR . $file);
        if (!$full || strncmp($full, $base, strlen($base)) !== 0 || !is_file($full)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return response()->download($full, basename($full), ['Content-Type' => 'text/plain; charset=UTF-8']);
    })->name('admin.logs.downloadq');

    // 4) Download file penuh (path version)
    Route::get('/download/{file}', function (string $file, Request $request) {
        $enabled = filter_var(env('LOG_VIEWER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) return response()->json(['ok' => false, 'error' => 'log_viewer_disabled'], 403);

        $allowed = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_EMAILS', ''))));
        $email   = strtolower((string) optional($request->user())->email);
        $okEmail = $email && in_array($email, array_map('strtolower', $allowed), true);
        if (!$okEmail) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $file)) return response()->json(['ok' => false, 'error' => 'bad_filename'], 400);

        $base = realpath(storage_path('logs')) ?: storage_path('logs');
        $full = realpath($base . DIRECTORY_SEPARATOR . $file);
        if (!$full || strncmp($full, $base, strlen($base)) !== 0 || !is_file($full)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return response()->download($full, basename($full), ['Content-Type' => 'text/plain; charset=UTF-8']);
    })->where('file', '[A-Za-z0-9._-]+')->name('admin.logs.download');

    // 5) Write test – paksa nulis 1 baris ke file log
    Route::post('/write-test', function (Request $request) {
        try {
            $enabled = filter_var(env('LOG_VIEWER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
            if (!$enabled) return response()->json(['ok' => false, 'error' => 'log_viewer_disabled'], 403);

            $allowed = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_EMAILS', ''))));
            $email   = strtolower((string) optional($request->user())->email);
            $okEmail = $email && in_array($email, array_map('strtolower', $allowed), true);
            if (!$okEmail) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);

            $dir = storage_path('logs');
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            if (!is_dir($dir)) return response()->json(['ok' => false, 'error' => 'mkdir_failed', 'dir' => $dir], 500);

            $channel = env('LOG_CHANNEL', 'daily');
            $file = $channel === 'daily' ? 'laravel-'.date('Y-m-d').'.log' : 'laravel.log';
            $full = $dir . DIRECTORY_SEPARATOR . $file;

            $line = '['.date('Y-m-d H:i:s').'] local.INFO: [TEST][LOG] hello from log viewer'
                  .' ip='.$request->ip().' ua='.($request->userAgent() ?? '-').PHP_EOL;

            $ok = @file_put_contents($full, $line, FILE_APPEND);
            if ($ok === false) return response()->json(['ok' => false, 'error' => 'write_failed', 'file' => $full], 500);

            @error_log('[WRITE-TEST] wrote to '.$full);

            return response()->json([
                'ok'        => true,
                'wrote'     => $line,
                'file'      => basename($full),
                'path'      => $full,
                'size_now'  => @filesize($full) ?: null,
                'channel'   => $channel,
            ]);
        } catch (\Throwable $e) {
            @error_log('[LOGS][WRITE-TEST][500] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
            return response()->json(['ok' => false, 'error' => 'server_error'], 500);
        }
    })->name('admin.logs.write-test');
});

// Prune token kedaluwarsa tiap 02:30 UTC
Schedule::command('pat:prune')->dailyAt('02:30');

// NOTE: Route /me tambahan (tanpa 'pat.expires'); biarkan sesuai file kamu
Route::middleware('auth:sanctum')->get('/me', function (Request $r) {
  $u = $r->user();
  return ['ok'=>true,'user'=>['id'=>$u->id,'name'=>$u->name,'email'=>$u->email,'role'=>$u->role]];
});

/**
 * Registrations (publik: throttled)
 */
Route::middleware('throttle:20,1')->post('/registrations', [RegistrationController::class, 'store']);

/**
 * List untuk FE/admin cepat (tanpa auth)
 */
Route::middleware('throttle:30,1')->get('/registrations-list', function (Request $r) {
    $q = trim((string) $r->query('q', ''));

    $rows = Registration::query()
        ->when($q !== '', function ($w) use ($q) {
            $w->where('instansi', 'like', "%$q%")
              ->orWhere('pic', 'like', "%$q%")
              ->orWhere('email', 'like', "%$q%")
              ->orWhere('wa', 'like', "%$q%")
              ->orWhere('kota', 'like', "%$q%")
              ->orWhere('provinsi', 'like', "%$q%");
        })
        ->orderByDesc('id')
        ->limit(200)
        ->get([
            'id','instansi','pic','jabatan','email','wa','alamat',
            'kelurahan','kecamatan','kota','provinsi','kodepos',
            'lat','lng','catatan','created_at'
        ]);

    return ['ok' => true, 'data' => $rows];
});

/**
 * Master data for FE
 */
Route::get('/master/yayasan', [MasterController::class, 'yayasan']);
Route::get('/master/sekolah/cities', [MasterController::class, 'sekolahCities']); // NEW
Route::get('/master/sekolah', [MasterController::class, 'sekolah']);
Route::get('/master/perusahaan', [MasterController::class, 'perusahaan']);

/**
 * Admin registrations (butuh auth)
 */
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    Route::get('/registrations', [RegistrationAdminController::class, 'index']);   // list + search + paginate
    Route::get('/registrations/{id}', [RegistrationAdminController::class, 'show']); // detail
    Route::post('/registrations', [RegistrationAdminController::class, 'store']);
    Route::patch('/registrations/{id}', [RegistrationAdminController::class, 'update']); // edit
    Route::delete('/registrations/{id}', [RegistrationAdminController::class, 'destroy']); // soft delete
});
