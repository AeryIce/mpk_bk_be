<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\MagicLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log; // <— tambahkan ini
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
            'email'   => ['required','string','email:rfc,dns','max:191'],
            'purpose' => ['required', Rule::in(['signup','reset'])],
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
        if (filter_var(env('EMAIL_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            if (config('mail.default') === 'log') {
                Log::info('Magic link URL (dev): ' . $url); // <— pakai Log::
            }
            /** @var MailableContract $mailable */
            $mailable = new MagicLinkMail($email, $purpose, $url);
            Mail::to($email)->send($mailable);
        }

        $payload = [
            'ok'         => true,
            'message'    => 'If an account exists, a magic link will be sent.',
            'purpose'    => $purpose,
            'expires_in' => (int) config('magiclink.ttl_minutes', 30) * 60,
        ];

        if (app()->environment('local')) {
            $payload['dev_token'] = $token;
        }

        return response()->json($payload);
    }

    /**
     * POST /api/auth/magic-link/consume
     */
    public function consume(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required','string','size:64'],
        ]);

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

        $link->used_at = now();
        $link->save();

        return response()->json([
            'ok'      => true,
            'email'   => $link->email,
            'purpose' => $link->purpose,
        ]);
    }
}
