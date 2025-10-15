<?php

namespace App\Http\Controllers;

use App\Models\Yayasan;
use App\Models\Sekolah;
use Illuminate\Http\Request;

class MasterController extends Controller
{
    public function yayasan(Request $req)
    {
        $q = trim(strtolower($req->query('q', '')));
        $limit = min(100, max(1, (int)$req->query('limit', 50)));

        $rows = Yayasan::query()
            ->when(strlen($q) >= 2, fn($qq) => $qq->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id','name']);

        return response()->json($rows);
    }

    public function sekolah(Request $req)
    {
        $yayasanId = trim($req->query('yayasanId', ''));
        if (!$yayasanId) return response()->json(['error'=>'yayasanId is required'], 400);

        $q = trim(strtolower($req->query('q', '')));
        $limit = min(200, max(1, (int)$req->query('limit', 100)));

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
}
