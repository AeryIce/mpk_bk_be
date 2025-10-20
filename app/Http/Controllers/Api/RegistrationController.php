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
        // 1) Validasi input (tetap seperti sebelumnya)
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

                // meta dari FE (opsional) – untuk guard & scoring
                'meta.path'         => ['nullable', 'in:perusahaan,yayasan,sekolah'],
                'meta.jenjang'      => ['nullable', 'string', 'max:50'],
                'meta.yayasanId'    => ['nullable', 'string', 'exists:yayasan,id'],
                'meta.yayasanLabel' => ['nullable', 'string', 'max:255'],
                'meta.kotaOpt'      => ['nullable', 'string', 'max:255'],
                'meta.sekolahId'    => ['nullable', 'string', 'exists:sekolah,id'],
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

        // 2) Guard ringan berbasis path (tetap)
        $path      = data_get($data, 'meta.path');
        $yayasanId = data_get($data, 'meta.yayasanId');
        $sekolahId = data_get($data, 'meta.sekolahId');

        if ($path === 'yayasan' && empty($yayasanId)) {
            return response()->json([
                'message' => 'Mohon pilih Yayasan yang valid.',
                'errors'  => ['meta.yayasanId' => ['Yayasan wajib dipilih pada mode Yayasan.']],
            ], 422);
        }
        if ($path === 'sekolah') {
            if (empty($yayasanId)) {
                return response()->json([
                    'message' => 'Mohon pilih Yayasan untuk mode Sekolah.',
                    'errors'  => ['meta.yayasanId' => ['Yayasan wajib dipilih pada mode Sekolah.']],
                ], 422);
            }
            if (empty($sekolahId)) {
                return response()->json([
                    'message' => 'Mohon pilih Sekolah yang valid.',
                    'errors'  => ['meta.sekolahId' => ['Sekolah wajib dipilih pada mode Sekolah.']],
                ], 422);
            }
        }

        // 3) Hard guard relasi sekolah→yayasan (tetap)
        if ($yayasanId && $sekolahId) {
            $belongsTo = DB::table('sekolah')->where('id', $sekolahId)->value('yayasan_id');
            if (!$belongsTo || (string)$belongsTo !== (string)$yayasanId) {
                return response()->json([
                    'message' => 'Sekolah tidak sesuai dengan yayasan yang dipilih.',
                    'errors'  => ['meta.sekolahId' => ['Sekolah tidak match dengan Yayasan.']],
                ], 422);
            }
        }

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
            // Tetap format respons lama agar FE aman
            return response()->json([
                'ok'  => true,
                'id'  => null,
                'msg' => 'Terima kasih! Data sudah kami terima.',
            ], 201);
        }

        // 4) Build payload simpan (tetap via Model)
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

        // 5) Anti-duplikasi (Soft-Single) — hanya aktif bila kolom sudah tersedia
        $hasDupCols = Schema::hasColumn('registrations', 'dup_group_id')
            && Schema::hasColumn('registrations', 'dup_score')
            && Schema::hasColumn('registrations', 'dup_reason')
            && Schema::hasColumn('registrations', 'is_primary')
            && Schema::hasColumn('registrations', 'status');

        $status       = 'new';
        $dupGroupId   = null;
        $isPrimary    = true;
        $dupScore     = 0;
        $dupReason    = 'none';

        if ($hasDupCols) {
            // Ambil kandidat terbaru secukupnya (cepat & efektif)
            $candidates = DB::table('registrations')
                ->select('id','instansi','email','wa','alamat','kota','provinsi','dup_group_id','is_primary','meta')
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
                $meta = is_array($row->meta) ? $row->meta : (json_decode($row->meta ?? '{}', true) ?: []);
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
                $dupGroupId = $bestGroup; // pakai grup kandidat
                $isPrimary  = false;
            } elseif ($bestScore >= 90) {
                $status     = 'possible_duplicate';
                $dupGroupId = $bestGroup;
                $isPrimary  = false;
            } else {
                $status     = 'new';
                $dupGroupId = null; // akan dibuat baru
                $isPrimary  = true;
            }

            if (!$dupGroupId) {
                $dupGroupId = (string) Str::uuid();
            }

            // sisipkan ke payload simpan
            $payload['dup_group_id'] = $dupGroupId;
            $payload['dup_score']    = $dupScore;
            $payload['dup_reason']   = $dupReason;
            $payload['is_primary']   = $isPrimary;
            $payload['status']       = $status;

            // simpan meta bila ada (jaga kompatibilitas)
            if (array_key_exists('meta', $data)) {
                $payload['meta'] = $data['meta'];
            }
        } else {
            // Kolom duplikasi belum ada → jalur lama (tanpa mengubah perilaku)
            if (array_key_exists('meta', $data)) {
                $payload['meta'] = $data['meta'];
            }
        }

        // 6) Simpan aman: coba Eloquent dulu, fallback ke DB insert jika mass-assignment ditolak
        try {
            $reg = Registration::create($payload);

            // set idempotency window 10 menit
            Cache::put($idemKey, 1, now()->addMinutes(10));

            return response()->json([
                'ok'  => true,
                'id'  => $reg->id,
                'msg' => 'Terima kasih! Data berhasil dikirim.',
            ], 201);

        } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
            try {
                $row = $payload;

                // timestamps
                $row['created_at'] = now();
                $row['updated_at'] = now();

                // encode meta jika masih array (untuk DB insert langsung)
                if (array_key_exists('meta', $row) && is_array($row['meta'])) {
                    $row['meta'] = json_encode($row['meta']);
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

    // ===== Helper normalisasi & scoring sederhana (inline agar tidak mengubah struktur proyek) =====

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
        if ($e1 !== '' && $e1 === $e2) {
            $score += 20; $reasons[] = 'email';
        }

        $w1 = $this->normPhone($incoming['wa'] ?? '');
        $w2 = $this->normPhone($candidate['wa'] ?? '');
        if ($w1 !== '' && $w1 === $w2) {
            $score += 20; $reasons[] = 'wa';
        }

        $over = $this->substrOverlapScore($incoming['alamat'] ?? '', $candidate['alamat'] ?? '');
        if ($over > 0) {
            $score += $over;
            $reasons[] = 'alamat';
        }

        return [$score, implode(', ', $reasons) ?: 'none'];
    }
}
