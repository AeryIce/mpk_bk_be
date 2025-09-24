<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\MagicLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use App\Mail\MagicLinkMail;
use Illuminate\Support\Facades\Cache;

class MagicLinkController extends Controller
{
    /**
     * Generator token 64 hex chars (tanpa random_bytes supaya linter adem).
     * NOTE: Kita generate plaintext token (untuk dikirim ke user),
     *       tapi yang DISIMPAN di DB adalah hash(token) → token_hash.
     */
    private function makeToken(): string
    {
        return hash('sha256', Str::uuid()->toString() . '|' . Str::random(40) . '|' . microtime(true));
    }

    /**
     * POST /api/auth/magic-link/request
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'   => ['required', 'string', 'email:rfc,dns', 'max:191'],
            'purpose' => ['required', Rule::in(['signup', 'reset'])],
        ]);

        $email   = strtolower(trim($data['email']));
        $purpose = $data['purpose'];

        // === COOLDOWN 20s per email+IP ===
        $cooldown = (int) config('magiclink.cooldown_seconds', 20);
        $keyCd = 'ml:cd:' . sha1($email.'|'.$request->ip());
        if (Cache::has($keyCd)) {
            return response()->json([
                'ok' => false,
                'error' => 'cooldown_active',
                'message' => "Please wait {$cooldown}s before requesting another magic link.",
            ], 429);
        }
        Cache::put($keyCd, 1, now()->addSeconds($cooldown));

        // Nonaktifkan token aktif sebelumnya untuk kombinasi email+purpose
        MagicLink::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // Generate plaintext token (64 hex) → hash → simpan hanya hash
        $token      = $this->makeToken();
        $token_hash = hash('sha256', $token);

        MagicLink::create([
            'id'         => (string) Str::uuid(), // UUID PK
            'email'      => $email,
            'token'      => null,                 // legacy off (tidak menyimpan plaintext)
            'token_hash' => $token_hash,          // simpan hash
            'purpose'    => $purpose,
            'expires_at' => now()->addMinutes((int) config('magiclink.ttl_minutes', 30)),
            'meta'       => ['ip' => $request->ip(), 'ua' => $request->userAgent()],
        ]);

        // Build URL magic-link (klik GET → consume)
        $fe   = (string) config('magiclink.frontend_url');
        $base = $fe !== '' ? rtrim($fe, '/') : rtrim(config('app.url'), '/');
        $url  = $base . '/api/auth/magic-link/consume/' . $token;

        // Kirim email via mailer aktif (SES di prod, log di lokal)
        $mailEnabled = filter_var(env('EMAIL_ENABLED', true), FILTER_VALIDATE_BOOL);
        $driver      = (string) config('mail.default');

        if ($mailEnabled) {
            try {
                if ($driver === 'log') {
                    Log::info('Magic link URL (dev): ' . $url);
                    error_log('[MAIL][dev-log] url=' . $url);
                }
                /** @var MailableContract $mailable */
                $mailable = new MagicLinkMail($email, $purpose, $url);
                Mail::to($email)->send($mailable);
            } catch (\Throwable $e) {
                $context = [
                    'email'   => $email,
                    'purpose' => $purpose,
                    'driver'  => $driver,
                    'error'   => $e->getMessage(),
                ];
                Log::error('[MAIL][send_failed]', $context);
                error_log('[MAIL][send_failed] ' . json_encode($context));
                // tetap 200
            }
        }

        $payload = [
            'ok'         => true,
            'message'    => 'If an account exists, a magic link will be sent.',
            'purpose'    => $purpose,
            'expires_in' => (int) config('magiclink.ttl_minutes', 30) * 60,
        ];

        if (app()->environment('local')) {
            // untuk dev only (jangan kirim ke prod)
            $payload['dev_token'] = $token;
            $payload['mailer']    = $driver;
        }

        return response()->json($payload);
    }

    /**
     * POST /api/auth/magic-link/consume
     * Body: { "token": "<64hex>" }
     *
     * Catatan:
     * - Magic token 64-hex → ditukar jadi Sanctum PAT (Bearer).
     * - Kalau kamu kirim PAT "8|...." ke sini, akan ditolak dengan pesan yang menjelaskan.
     */
    public function consume(Request $request): JsonResponse
    {
        // Deteksi keliru: kalau yang dikirim malah PAT "8|...."
        if (is_string($request->input('token')) && str_contains($request->input('token'), '|')) {
            return response()->json([
                'ok' => false,
                'error' => 'looks_like_pat',
                'message' => 'You sent a Personal Access Token. Use it as Authorization: Bearer <token> for /api/me or /api/logout, not in /consume.',
            ], 422);
        }

        // Validasi magic token 64 hex (lowercase)
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ], [
            'token.size'  => 'Magic-link token must be exactly 64 hex characters.',
            'token.regex' => 'Magic-link token must be a lowercase hex string (0-9 a-f).',
        ]);

        $provided = $data['token'];
        $hash     = hash('sha256', $provided);

        // 1) Cari berdasarkan token_hash (skema baru)
        $link = MagicLink::query()->where('token_hash', $hash)->first();

        // 1b) BACKWARD-COMPAT: kalau tidak ketemu, coba kolom legacy 'token'
        if (!$link) {
            $link = MagicLink::query()->where('token', $provided)->first();
            if ($link) {
                // migrasi satu-kali: isi hash & kosongkan plaintext
                $link->token_hash = $hash;
                $link->token = null;
                $link->save();
            }
        }

        if (!$link) {
            return response()->json(['ok' => false, 'error' => 'invalid_token'], 422);
        }
        if ($link->used_at) {
            return response()->json(['ok' => false, 'error' => 'already_used'], 422);
        }
        if ($link->expires_at && $link->expires_at->isPast()) {
            return response()->json(['ok' => false, 'error' => 'expired'], 422);
        }

        // 2) Tandai used
        $link->used_at = now();
        $link->save();

        // 3) Buat/ambil user
        $email = strtolower(trim($link->email));
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'     => Str::before($email, '@'),
                'password' => Str::password(32), // random; diproses via cast 'hashed'
            ]
        );

        // 4) Issue Sanctum PAT + isi expiry & meta (IP/UA)
        //    NOTE: Pastikan migration kolom expires_at, ip_address, user_agent sudah dijalankan.
        $ttlHours  = (int) env('PAT_TTL_HOURS', 24);
        $expiresAt = now()->addHours($ttlHours);

        $newToken = $user->createToken('magiclink', ['*']); // abilities opsional
        $patModel = $newToken->accessToken;                 // \Laravel\Sanctum\PersonalAccessToken

        // simpan meta & expiry
        $patModel->expires_at = $expiresAt;
        $patModel->ip_address = $request->ip();
        $patModel->user_agent = (string) $request->userAgent();
        $patModel->save();

        // 5) Response → plainTextToken dipakai sebagai Bearer
        return response()->json([
            'ok'      => true,
            'token'   => $newToken->plainTextToken, // Bearer PAT
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'purpose' => $link->purpose,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }
}
