<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Auth\MagicLinkController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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

    // Request magic link (rate limit pakai key khusus dari AppServiceProvider)
  // GANTI limiter: magiclink-email
    Route::post('/magic-link/request', [MagicLinkController::class, 'request'])
        ->middleware('throttle:magiclink-email')
        ->name('auth.magiclink.request');

    // GANTI limiter: magiclink-consume
    Route::post('/magic-link/consume', [MagicLinkController::class, 'consume'])
        ->middleware('throttle:magiclink-consume')
        ->name('auth.magiclink.consume');

    Route::get('/magic-link/consume/{token}', function (string $token, Request $r) {
        $request = $r->merge(['token' => $token]);
        return app(MagicLinkController::class)->consume($request);
    })
        ->middleware('throttle:magiclink-consume')
        ->name('auth.magiclink.consume.get');
});

/**
 * PROTECTED ROUTES (Sanctum Bearer)
 */
Route::middleware('auth:sanctum')->get('/me', function (\Illuminate\Http\Request $request) {
    $u = $request->user();
    if (!$u) {
        // fallback defensif: kalau middleware lolos tapi user null, balikin 401
        return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    return response()->json([
        'ok' => true,
        'user' => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ],
    ]);
})->name('auth.me');

Route::middleware('auth:sanctum')->post('/logout', function (\Illuminate\Http\Request $request) {
    $token = $request->user()?->currentAccessToken();
    if ($token) {
        $token->delete();
    }
    return response()->json(['ok' => true]);
})->name('auth.logout');

/**
 * PROTECTED LOGS (Sanctum Bearer,ENV Toggle ,only for admins agahariiswarajati@gmail.com)
 */

Route::middleware('auth:sanctum')->prefix('admin/logs')->group(function () {
    // GET /api/admin/logs  -> list file log
    Route::get('/', function (\Illuminate\Http\Request $request) {
        // Gate by env toggle
        $enabled = filter_var(env('LOG_VIEWER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return response()->json(['ok' => false, 'error' => 'log_viewer_disabled'], 403);
        }

        // Gate by email whitelist
        $allowed = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_EMAILS', ''))));
        $email   = strtolower((string) optional($request->user())->email);
        $okEmail = $email && in_array($email, array_map('strtolower', $allowed), true);
        if (!$okEmail) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $base = storage_path('logs');
        if (!is_dir($base)) {
            return response()->json(['ok' => true, 'files' => []]);
        }

        $files = collect(File::files($base))->map(function (\SplFileInfo $f) {
            return [
                'name'        => $f->getFilename(),
                'size_bytes'  => $f->getSize(),
                'modified_at' => date('c', $f->getMTime()),
            ];
        })->sortByDesc('modified_at')->values();

        return response()->json(['ok' => true, 'path' => $base, 'files' => $files]);
    })->name('admin.logs.index');

    // GET /api/admin/logs/view/{file}?bytes=65536&raw=0
    Route::get('/view/{file}', function (string $file, \Illuminate\Http\Request $request) {
        $enabled = filter_var(env('LOG_VIEWER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) return response()->json(['ok' => false, 'error' => 'log_viewer_disabled'], 403);

        $allowed = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_EMAILS', ''))));
        $email   = strtolower((string) optional($request->user())->email);
        $okEmail = $email && in_array($email, array_map('strtolower', $allowed), true);
        if (!$okEmail) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);

        // Sanitasi nama file: hanya huruf/angka/._-
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
            return response()->json(['ok' => false, 'error' => 'bad_filename'], 400);
        }

        $base = realpath(storage_path('logs')) ?: storage_path('logs');
        $full = realpath($base . DIRECTORY_SEPARATOR . $file);
        if (!$full || strncmp($full, $base, strlen($base)) !== 0 || !is_file($full)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $bytes = (int) $request->query('bytes', 65536); // default 64KB
        $bytes = max(1024, min($bytes, 2 * 1024 * 1024)); // 1KB .. 2MB
        $size  = filesize($full) ?: 0;

        $start = $size > $bytes ? $size - $bytes : 0;
        $fh = fopen($full, 'rb');
        if ($fh === false) {
            return response()->json(['ok' => false, 'error' => 'cannot_open'], 500);
        }
        if ($start > 0) fseek($fh, $start);
        $content = stream_get_contents($fh) ?: '';
        fclose($fh);

        // Raw text mode? (enak buat preview langsung)
        $raw = (bool) $request->query('raw', false);
        if ($raw) {
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
            'modified_at' => date('c', filemtime($full)),
            'content'     => $content,
        ]);
    })->name('admin.logs.view');

    // GET /api/admin/logs/download/{file}
    Route::get('/download/{file}', function (string $file, \Illuminate\Http\Request $request) {
        $enabled = filter_var(env('LOG_VIEWER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) return response()->json(['ok' => false, 'error' => 'log_viewer_disabled'], 403);

        $allowed = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_EMAILS', ''))));
        $email   = strtolower((string) optional($request->user())->email);
        $okEmail = $email && in_array($email, array_map('strtolower', $allowed), true);
        if (!$okEmail) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
            return response()->json(['ok' => false, 'error' => 'bad_filename'], 400);
        }

        $base = realpath(storage_path('logs')) ?: storage_path('logs');
        $full = realpath($base . DIRECTORY_SEPARATOR . $file);
        if (!$full || strncmp($full, $base, strlen($base)) !== 0 || !is_file($full)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return response()->download($full, basename($full), ['Content-Type' => 'text/plain; charset=UTF-8']);
    })->name('admin.logs.download');
});
