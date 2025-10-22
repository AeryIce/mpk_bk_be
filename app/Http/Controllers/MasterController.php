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
        $limit = min(100, max(1, (int)$req->query('limit', 50)));

        $rows = Yayasan::query()
            ->when(strlen($q) >= 2, fn($qq) => $qq->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id','name']);

        return response()->json($rows);
    }

    // GET /api/master/sekolah?yayasanId=38&limit=500[&q=...]
    // ⛳️ Kembali ke perilaku lama: abaikan jenjang/kota; cukup yayasanId (+ optional q)
    public function sekolah(Request $req)
    {
        $yayasanId = trim($req->query('yayasanId', ''));
        if (!$yayasanId) return response()->json(['error'=>'yayasanId is required'], 400);

        $q     = trim(strtolower($req->query('q', '')));
        $limit = min(500, max(1, (int)$req->query('limit', 100)));

        $rows = Sekolah::query()
            ->where('yayasan_id', $yayasanId)
            ->when(strlen($q) >= 2, function($qq) use ($q) {
                $qq->where(function($w) use ($q) {
                    $w->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                      ->orWhereRaw('LOWER(jenjang) LIKE ?', ["%{$q}%"]);
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id','name','jenjang','kecamatan','kabupaten','provinsi','npsn']);

        return response()->json($rows);
    }

    // GET /api/master/sekolah/cities?yayasanId=38&jenjang=TK
    // 🔕 Opsional untuk FE: selalu 200, taruh "(Semua Kota)" di depan
    public function sekolahCities(Request $req)
    {
        $yayasanId = (int) $req->query('yayasanId', 0);
        if ($yayasanId <= 0) return response()->json(['error' => 'yayasanId is required'], 400);

        $jenjang = $this->normJenjang($req->query('jenjang'));

        $cities = Sekolah::query()
            ->where('yayasan_id', $yayasanId)
            ->when($jenjang, fn ($w) => $w->whereRaw('LOWER(jenjang) = ?', [$jenjang]))
            ->orderBy('kabupaten')
            ->pluck('kabupaten')
            ->unique()
            ->filter()
            ->values()
            ->all();

        $result = array_values(array_unique(array_merge(['(Semua Kota)'], $cities)));
        return response()->json(['ok' => true, 'data' => $result]);
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
            ->get(['id', 'name'])
            ->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name])
            ->values();

        return response()->json($rows);
    }

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
