<?php

namespace App\Http\Controllers;

use App\Models\Yayasan;
use App\Models\Sekolah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MasterController extends Controller
{
    // GET /api/master/yayasan?q=...&limit=50
    public function yayasan(Request $req)
    {
        $q     = trim(strtolower($req->query('q', '')));
        $limit = min(100, max(1, (int) $req->query('limit', 50)));

        $rows = Yayasan::query()
            ->when(strlen($q) >= 2, fn ($qq) => $qq->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id','name']);

        return response()->json($rows);
    }

    // GET /api/master/sekolah/cities?yayasanId=38&jenjang=TK
    // -> aman: selalu 200 + sisipkan "(Semua Kota)" agar FE bisa lanjut walau user belum pilih kota
    public function sekolahCities(Request $req)
    {
        $yayasanId = (int) $req->query('yayasanId', 0);
        if ($yayasanId <= 0) {
            return response()->json(['error' => 'yayasanId is required'], 400);
        }

        $jenjang = $this->normJenjang($req->query('jenjang'));

        $cities = Sekolah::query()
            ->where('yayasan_id', $yayasanId)
            ->when($jenjang, fn ($w) => $w->whereRaw('LOWER(jenjang) = ?', [$jenjang]))
            ->orderBy('kabupaten')
            ->pluck('kabupaten')
            ->unique()
            ->filter() // buang null/empty
            ->values()
            ->all();

        $result = array_values(array_unique(array_merge(['(Semua Kota)'], $cities)));
        return response()->json(['ok' => true, 'data' => $result]);
    }

    // GET /api/master/sekolah?yayasanId=38&jenjang=TK&kota=Jakarta%20Barat&q=...&limit=500
    public function sekolah(Request $req)
    {
        $yayasanId = (int) $req->query('yayasanId', 0);
        if ($yayasanId <= 0) return response()->json(['error'=>'yayasanId is required'], 400);

        $limit   = min(500, max(1, (int) $req->query('limit', 100)));
        $jenjang = $this->normJenjang($req->query('jenjang')); // opsional
        $kotaRaw = (string) $req->query('kota', '');            // opsional (FE tidak punya field ini)
        $q       = trim(strtolower($req->query('q', '')));

        // jika kota kosong atau placeholder -> JANGAN filter kota
        $ignoreKota = $kotaRaw === '' || in_array(strtolower($kotaRaw), [
            '(semua kota)','semua','semua kota','all','__all__'
        ], true);

        $rows = Sekolah::query()
            ->where('yayasan_id', $yayasanId)
            ->when($jenjang, fn ($w) => $w->whereRaw('LOWER(jenjang) = ?', [$jenjang]))
            ->when(!$ignoreKota, fn ($w) => $w->where('kabupaten', $kotaRaw))
            ->when(strlen($q) >= 2, function($qq) use ($q) {
                $qq->where(function($w) use ($q) {
                    $w->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                      ->orWhereRaw('LOWER(jenjang) LIKE ?', ["%{$q}%"]);
                });
            })
            ->orderBy('kabupaten')->orderBy('name')
            ->limit($limit)
            ->get(['id','name','jenjang','kecamatan','kabupaten','provinsi','npsn']);

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    // GET /api/master/perusahaan?q=...&limit=50
    public function perusahaan(Request $r)
    {
        $q     = trim((string) $r->query('q', ''));
        $limit = max(1, min((int) $r->query('limit', 50), 500));

        $rows = DB::table('master_perusahaan')
            ->when($q !== '', function ($w) use ($q) {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') $w->where('name', 'ILIKE', "%{$q}%");
                else $w->where('name', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id','name'])
            ->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name])
            ->values();

        return response()->json($rows);
    }

    // helper normalisasi jenjang (biar TK/SD/SMP/SMA/SMK konsisten)
    private function normJenjang(?string $j): ?string
    {
        if (!$j) return null;
        $j = strtolower(trim($j));
        $map = [
            'tk'=>'tk','t.k'=>'tk','kindergarten'=>'tk',
            'sd'=>'sd','s d'=>'sd','primary'=>'sd',
            'smp'=>'smp','junior'=>'smp',
            'sma'=>'sma','senior'=>'sma',
            'smk'=>'smk','vocational'=>'smk',
        ];
        return $map[$j] ?? $j;
    }
}
