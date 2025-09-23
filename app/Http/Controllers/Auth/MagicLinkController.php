<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\MagicLink;
use App\Models\User; // <— add
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use App\Mail\MagicLinkMail;

class MagicLinkController extends Controller
{
    /**
     * Generator token 64 hex chars (tanpa random_bytes supaya linter adem).
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

        // Nonaktifkan token aktif sebelumnya untuk kombinasi email+purpose
        MagicLink::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = $this->makeToken();

        MagicLink::create([
            'id'         => (string) Str::uuid(), // UUID PK
            'email'      => $email,
            'token'      => $token,
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
                    error_log('[MAIL][dev-log] url=' . $url); // tampil di Railway
                }

                /** @var MailableContract $mailable */
                $mailable = new MagicLinkMail($email, $purpose, $url);
                Mail::to($email)->send($mailable);
            } catch (\Throwable $e) {
                // Tulis ke Laravel log & stderr supaya keliatan di Railway
                $context = [
                    'email'   => $email,
                    'purpose' => $purpose,
                    'driver'  => $driver,
                    'error'   => $e->getMessage(),
                ];
                Log::error('[MAIL][send_failed]', $context);
                error_log('[MAIL][send_failed] ' . json_encode($context));
                // Jangan lempar error ke client → tetap 200
            }
        }

        $payload = [
            'ok'         => true,
            'message'    => 'If an account exists, a magic link will be sent.',
            'purpose'    => $purpose,
            'expires_in' => (int) config('magiclink.ttl_minutes', 30) * 60,
        ];

        if (app()->environment('local')) {
            $payload['dev_token'] = $token;
            $payload['mailer']    = $driver;
        }

        return response()->json($payload);
    }

    /**
     * POST /api/auth/magic-link/consume
     * Body: { "token": "<64hex>" }
     *
     * NOTE: S-2 nanti kita ganti ke hash-compare. Sekarang masih plaintext kolom `token`.
     */
    public function consume(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);

        // 1) Ambil link berdasarkan token (validations)
        $link = MagicLink::query()->where('token', $data['token'])->first();

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

        // 3) Pastikan user ada (firstOrCreate) — name default dari bagian sebelum '@'
        $email = strtolower(trim($link->email));
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'     => Str::before($email, '@'),
                // password random (tidak dipakai untuk login magic link)
                'password' => Str::password(32),
            ]
        );

        // 4) Issue Sanctum token (Bearer)
        $token = $user->createToken('magic-link')->plainTextToken;

        // 5) Response standar FE
        return response()->json([
            'ok'      => true,
            'token'   => $token,
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'purpose' => $link->purpose,
        ]);
    }
}
