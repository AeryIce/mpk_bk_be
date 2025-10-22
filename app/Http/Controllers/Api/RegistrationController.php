<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Registration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RegistrationController extends Controller
{
    public function store(Request $request)
    {
        // 1) Validasi input (utama) — meta dilonggarkan (tanpa exists) supaya tidak 422
        $data = $request->validate(
            [
                'instansi'   => ['required', 'string', 'max:255'],
                'pic'        => ['required', 'string', 'max:255'],
                'jabatan'    => ['nullable', 'string', 'max:255'],

                'email'      => ['required', 'email', 'max:255'],
                'wa'         => ['required', 'string', 'max:50'],

                'alamat'     => ['required', 'string'],
                'kelurahan'  => ['nullable', 'string', 'max:255'],
                'kecamatan'  => ['nullable', 'string', 'max:255'],
                'kota'       => ['required', 'string', 'max:255'],
                'provinsi'   => ['required', 'string', 'max:255'],
                'kodepos'    => ['nullable', 'string', 'max:20'],

                'lat'        => ['nullable', 'numeric', 'between:-90,90'],
                'lng'        => ['nullable', 'numeric', 'between:-180,180'],

                'catatan'    => ['nullable', 'string'],

                // meta dari FE (opsional) – dilonggarkan
                'meta.path'         => ['nullable', 'in:perusahaan,yayasan,sekolah'],
                'meta.jenjang'      => ['nullable', 'string', 'max:50'],
                'meta.yayasanId'    => ['nullable', 'string'],
                'meta.yayasanLabel' => ['nullable', 'string', 'max:255'],
                'meta.kotaOpt'      => ['nullable', 'string', 'max:255'],
                'meta.sekolahId'    => ['nullable', 'string'],
            ],
            [],
            [
                'instansi' => 'Instansi/Sekolah',
                'pic'      => 'Nama PIC',
                'wa'       => 'No WhatsApp',
                'kota'     => 'Kota/Kabupaten',
                'provinsi' => 'Provinsi',
            ]
        );

        // 2) Normalisasi + guard ringan (FAIL-SOFT, tanpa 422)
        $path      = data_get($data, 'meta.path');
        $yayasanId = trim((string) data_get($data, 'meta.yayasanId', ''));
        $sekolahId = trim((string) data_get($data, 'meta.sekolahId', ''));

        // normalisasi path
        if (!in_array($path, ['perusahaan','yayasan','sekolah'], true)) {
            $path = null;
        }

        // auto-fallback: mode sekolah tapi user belum klik saran → turunkan ke yayasan
        if ($path === 'sekolah' && $sekolahId === '' && $yayasanId !== '') {
            $path = 'yayasan';
        }

        // cek keberadaan ID (tanpa menggagalkan submit)
        $yayasanValid = $yayasanId !== '' && DB::table('yayasan')->where('id', $yayasanId)->exists();
        $sekolahValid = $sekolahId !== '' && DB::table('sekolah')->where('id', $sekolahId)->exists();

        if ($path === 'yayasan' && !$yayasanValid) {
            $path = null; // simpan tanpa link
        }
        if ($path === 'sekolah') {
            if (!$yayasanValid || !$sekolahValid) {
                $path = null;
            } else {
                // 3) Hard guard relasi sekolah→yayasan (fail-soft)
                $okRel = DB::table('sekolah')
                    ->where('id', $sekolahId)
                    ->where('yayasan_id', $yayasanId)
                    ->exists();
                if (!$okRel) {
                    $path = null; // relasi tak cocok → simpan tanpa link
                }
            }
        }

        // tulis kembali path hasil normalisasi ke meta (kalau meta ada)
        if (!isset($data['meta']) || !is_array($data['meta'])) {
            $data['meta'] = [];
        }
        $data['meta']['path'] = $path;

        // 3.1) Idempotency window 10 menit (hindari double-tap submit)
        $idemKey = 'idem:registrations:' . sha1(json_encode([
            $this->normText($data['instansi'] ?? ''),
            $this->normText($data['email'] ?? ''),
            $this->normPhone($data['wa'] ?? ''),
            $this->normText($data['alamat'] ?? ''),
            $this->normText($data['kota'] ?? ''),
            $this->normText($data['provinsi'] ?? ''),
        ]));
        if (Cache::has($idemKey)) {
            return response()->json([
                'ok'  => true,
                'id'  => null,
                'msg' => 'Terima kasih! Data sudah kami terima.',
            ], 201);
        }

        // 4) Build payload simpan (via Model)
        $payload = [
            'instansi'  => $data['instansi'],
            'pic'       => $data['pic'],
            'jabatan'   => $data['jabatan'] ?? null,
            'email'     => $data['email'],
            'wa'        => $data['wa'],
            'alamat'    => $data['alamat'],
            'kelurahan' => $data['kelurahan'] ?? null,
            'kecamatan' => $data['kecamatan'] ?? null,
            'kota'      => $data['kota'],
            'provinsi'  => $data['provinsi'],
            'kodepos'   => $data['kodepos'] ?? null,
            'lat'       => $data['lat'] ?? null,
            'lng'       => $data['lng'] ?? null,
            'catatan'   => $data['catatan'] ?? null,
        ];

        // Cek kolom opsional
        $hasDupCols = Schema::hasColumn('registrations', 'dup_group_id')
            && Schema::hasColumn('registrations', 'dup_score')
            && Schema::hasColumn('registrations', 'dup_reason')
            && Schema::hasColumn('registrations', 'is_primary')
            && Schema::hasColumn('registrations', 'status');

        $hasMetaCol = Schema::hasColumn('registrations', 'meta');

        // Default status & duplicate flags
        $status       = 'new';
        $dupGroupId   = null;
        $isPrimary    = true;
        $dupScore     = 0;
        $dupReason    = 'none';

        if ($hasDupCols) {
            // SELECT dinamis (hindari select kolom meta jika belum ada)
            $selectCols = ['id','instansi','email','wa','alamat','kota','provinsi','dup_group_id','is_primary'];
            if ($hasMetaCol) $selectCols[] = 'meta';

            $candidates = DB::table('registrations')
                ->select($selectCols)
                ->orderByDesc('id')
                ->limit(1000)
                ->get();

            $incoming = [
                'instansi'  => $data['instansi'] ?? '',
                'email'     => $data['email'] ?? '',
                'wa'        => $data['wa'] ?? '',
                'alamat'    => $data['alamat'] ?? '',
                'kota'      => $data['kota'] ?? '',
                'provinsi'  => $data['provinsi'] ?? '',
                'sekolahId' => data_get($data, 'meta.sekolahId'),
            ];

            $bestScore  = -1;
            $bestReason = 'none';
            $bestGroup  = null;

            foreach ($candidates as $row) {
                $meta = [];
                if ($hasMetaCol && property_exists($row, 'meta')) {
                    $meta = is_array($row->meta) ? $row->meta : (json_decode($row->meta ?? '{}', true) ?: []);
                }

                $cand = [
                    'instansi'  => $row->instansi,
                    'email'     => $row->email,
                    'wa'        => $row->wa,
                    'alamat'    => $row->alamat,
                    'kota'      => $row->kota,
                    'provinsi'  => $row->provinsi,
                    'sekolahId' => $meta['sekolahId'] ?? null,
                ];
                [$score, $reason] = $this->scoreAgainst($incoming, $cand);
                if ($score > $bestScore) {
                    $bestScore  = $score;
                    $bestReason = $reason;
                    $bestGroup  = $row->dup_group_id;
                }
            }

            $dupScore  = max(0, $bestScore);
            $dupReason = $bestReason;

            if ($bestScore >= 100) {
                $status     = 'duplicate';
                $dupGroupId = $bestGroup;
                $isPrimary  = false;
            } elseif ($bestScore >= 90) {
                $status     = 'possible_duplicate';
                $dupGroupId = $bestGroup;
                $isPrimary  = false;
            } else {
                $status     = 'new';
                $dupGroupId = null;
                $isPrimary  = true;
            }

            if (!$dupGroupId) {
                $dupGroupId = (string) Str::uuid();
            }

            $payload['dup_group_id'] = $dupGroupId;
            $payload['dup_score']    = $dupScore;
            $payload['dup_reason']   = $dupReason;
            $payload['is_primary']   = $isPrimary;
            $payload['status']       = $status;
        }

        // Simpan meta hanya kalau kolom ada
        if ($hasMetaCol && array_key_exists('meta', $data)) {
            $payload['meta'] = $data['meta'];
        }

        // 6) Simpan: coba Eloquent, fallback ke query builder jika mass-assignment off
        try {
            $reg = Registration::create($payload);

            Cache::put($idemKey, 1, now()->addMinutes(10));

            return response()->json([
                'ok'  => true,
                'id'  => $reg->id,
                'msg' => 'Terima kasih! Data berhasil dikirim.',
            ], 201);

        } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
            try {
                $row = $payload;

                $row['created_at'] = now();
                $row['updated_at'] = now();

                if ($hasMetaCol && array_key_exists('meta', $row) && is_array($row['meta'])) {
                    $row['meta'] = json_encode($row['meta']);
                } else {
                    unset($row['meta']);
                }

                $id = DB::table('registrations')->insertGetId($row);

                Cache::put($idemKey, 1, now()->addMinutes(10));

                return response()->json([
                    'ok'  => true,
                    'id'  => $id,
                    'msg' => 'Terima kasih! Data berhasil dikirim.',
                ], 201);

            } catch (\Throwable $e2) {
                Log::error('[REG][STORE][DB-FALLBACK] '.$e2->getMessage(), [
                    'file' => $e2->getFile(), 'line' => $e2->getLine()
                ]);
                return response()->json([
                    'ok'      => false,
                    'message' => 'Gagal menyimpan pendaftaran (fallback).',
                ], 500);
            }

        } catch (\Throwable $e) {
            Log::error('[REG][STORE] '.$e->getMessage(), ['file'=>$e->getFile(),'line'=>$e->getLine()]);
            return response()->json([
                'ok'      => false,
                'message' => 'Gagal menyimpan pendaftaran.',
            ], 500);
        }
    }

    // ===== Helper normalisasi & scoring sederhana =====

    private function normText(?string $v): string
    {
        if ($v === null) return '';
        $v = mb_strtolower(trim($v), 'UTF-8');
        $v = preg_replace('/\s+/u', ' ', $v);
        $v = preg_replace('/[^\p{L}\p{Nd}\s\-\.,]/u', '', $v);
        return trim($v ?? '');
    }

    private function normPhone(?string $v): string
    {
        if ($v === null) return '';
        $digits = preg_replace('/\D+/', '', $v);
        if (str_starts_with($digits, '0')) {
            return '+62' . substr($digits, 1);
        }
        if (str_starts_with($digits, '62')) {
            return '+' . $digits;
        }
        if (str_starts_with($v, '+')) {
            return $v;
        }
        return $digits;
    }

    private function substrOverlapScore(string $a, string $b): int
    {
        $a = $this->normText($a);
        $b = $this->normText($b);
        if ($a === '' || $b === '') return 0;
        $sub = mb_substr($a, 0, 12, 'UTF-8');
        if ($sub !== '' && mb_strpos($b, $sub, 0, 'UTF-8') !== false) {
            return 10;
        }
        return 0;
    }

    /**
     * Skoring duplikasi:
     * - sekolahId sama → +100 (hard match)
     * - instansi+kota+provinsi sama → +90
     * - email sama → +20
     * - wa (E.164) sama → +20
     * - alamat overlap substring → +10
     */
    private function scoreAgainst(array $incoming, array $candidate): array
    {
        $score = 0;
        $reasons = [];

        // Hard match: sekolahId
        if (!empty($incoming['sekolahId']) && !empty($candidate['sekolahId'])
            && (string)$incoming['sekolahId'] === (string)$candidate['sekolahId']) {
            $score += 100;
            $reasons[] = 'sekolahId';
        } else {
            $i1 = $this->normText($incoming['instansi'] ?? '');
            $i2 = $this->normText($candidate['instansi'] ?? '');
            $k1 = $this->normText($incoming['kota'] ?? '');
            $k2 = $this->normText($candidate['kota'] ?? '');
            $p1 = $this->normText($incoming['provinsi'] ?? '');
            $p2 = $this->normText($candidate['provinsi'] ?? '');

            if ($i1 !== '' && $i1 === $i2 && $k1 !== '' && $k1 === $k2 && $p1 !== '' && $p1 === $p2) {
                $score += 90;
                $reasons[] = 'instansi+kota+provinsi';
            }
        }

        $e1 = $this->normText($incoming['email'] ?? '');
        $e2 = $this->normText($candidate['email'] ?? '');
        if ($e1 !== '' && $e1 === $e2) { $score += 20; $reasons[] = 'email'; }

        $w1 = $this->normPhone($incoming['wa'] ?? '');
        $w2 = $this->normPhone($candidate['wa'] ?? '');
        if ($w1 !== '' && $w1 === $w2) { $score += 20; $reasons[] = 'wa'; }

        $over = $this->substrOverlapScore($incoming['alamat'] ?? '', $candidate['alamat'] ?? '');
        if ($over > 0) { $score += $over; $reasons[] = 'alamat'; }

        return [$score, implode(', ', $reasons) ?: 'none'];
    }
}
