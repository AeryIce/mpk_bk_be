<?php

namespace App\Http\Controllers;

use App\Models\Yayasan;
use App\Models\Sekolah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MasterController extends Controller
{
    /**
     * GET /api/master/yayasan?q=...&limit=50
     */
    public function yayasan(Request $req)
    {
        $q     = trim(strtolower($req->query('q', '')));
        $limit = min(100, max(1, (int) $req->query('limit', 50)));

        $rows = Yayasan::query()
            ->when(strlen($q) >= 2, fn ($qq) => $qq->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name']);

        return response()->json($rows);
    }

    /**
     * GET /api/master/sekolah/cities?yayasanId=38&jenjang=TK
     * Kembalikan list kota/kabupaten unik untuk Yayasan + (opsional) jenjang
     */
    public function sekolahCities(Request $req)
    {
        $data = $req->validate([
            'yayasanId' => 'required|integer',
            'jenjang'   => 'nullable|string',
        ]);

        $jenjang = $this->normJenjang($data['jenjang'] ?? null);

        $cities = Sekolah::query()
            ->where('yayasan_id', $data['yayasanId'])
            ->when($jenjang, fn ($w) => $w->whereRaw('LOWER(jenjang) = ?', [$jenjang]))
            ->orderBy('kabupaten')
            ->pluck('kabupaten')   // gunakan kolom 'kabupaten' sebagai city
            ->unique()
            ->values();

        return response()->json(['ok' => true, 'data' => $cities]);
    }

    /**
     * GET /api/master/sekolah?yayasanId=38&jenjang=TK&kota=Jakarta%20Barat&q=bellarminus&limit=50
     * List sekolah dengan filter akurat (yayasan wajib; jenjang/kota/q opsional)
     */
    public function sekolah(Request $req)
    {
        $data = $req->validate([
            'yayasanId' => 'required|integer',
            'jenjang'   => 'nullable|string',
            'kota'      => 'nullable|string', // FE boleh kirim 'kota' -> kita cocokkan ke kolom 'kabupaten'
            'q'         => 'nullable|string',
            'limit'     => 'nullable|integer|min:1|max:200',
        ]);

        $limit   = $data['limit'] ?? 100;
        $jenjang = $this->normJenjang($data['jenjang'] ?? null);
        $kota    = $data['kota'] ?? null;
        $q       = trim(strtolower($data['q'] ?? ''));

        $rows = Sekolah::query()
            ->where('yayasan_id', $data['yayasanId'])
            ->when($jenjang, fn ($w) => $w->whereRaw('LOWER(jenjang) = ?', [$jenjang]))
            ->when($kota, fn ($w)         => $w->where('kabupaten', $kota))
            ->when(strlen($q) >= 2, function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                      ->orWhereRaw('LOWER(jenjang) LIKE ?', ["%{$q}%"]);
                });
            })
            ->orderBy('kabupaten')->orderBy('name')
            ->limit($limit)
            ->get(['id','name','jenjang','kecamatan','kabupaten','provinsi','npsn']);

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /**
     * GET /api/master/perusahaan?q=...&limit=50
     */
    public function perusahaan(Request $r)
    {
        $q     = trim((string) $r->query('q', ''));
        $limit = (int) $r->query('limit', 50);
        $limit = max(1, min($limit, 500));

        $rows = DB::table('master_perusahaan')
            ->when($q !== '', function ($w) use ($q) {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    $w->where('name', 'ILIKE', "%{$q}%");
                } else {
                    $w->where('name', 'like', "%{$q}%");
                }
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name])
            ->values();

        return response()->json($rows);
    }

    /**
     * Normalisasi string jenjang agar filter akurat (case-insensitive, variasi istilah)
     */
    private function normJenjang(?string $j): ?string
    {
        if (!$j) return null;
        $j = strtolower(trim($j));
        $map = [
            'tk' => 'tk', 't.k' => 'tk', 'kindergarten' => 'tk',
            'sd' => 'sd', 's d' => 'sd', 'primary' => 'sd',
            'smp' => 'smp', 'junior' => 'smp',
            'sma' => 'sma', 'senior' => 'sma',
            'smk' => 'smk', 'vocational' => 'smk',
        ];
        return $map[$j] ?? $j;
    }
}
