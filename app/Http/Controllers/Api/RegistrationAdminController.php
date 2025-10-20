<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegistrationAdminController extends Controller
{
    public function index(Request $req)
    {
        $q = trim((string) $req->query('search', ''));
        $per = max(1, min((int)$req->query('perPage', 20), 200));

        $rows = Registration::query()
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('instansi', 'ILIKE', "%$q%")
                      ->orWhere('pic', 'ILIKE', "%$q%")
                      ->orWhere('email', 'ILIKE', "%$q%")
                      ->orWhere('wa', 'ILIKE', "%$q%")
                      ->orWhere('kota', 'ILIKE', "%$q%")
                      ->orWhere('provinsi', 'ILIKE', "%$q%")
                      ->orWhere('alamat', 'ILIKE', "%$q%");
                });
            })
            ->orderByDesc('id')
            ->paginate($per);

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function show($id)
    {
        $row = Registration::find($id);
        if (!$row) return response()->json(['ok'=>false,'message'=>'Not found'], 404);
        return response()->json(['ok'=>true,'data'=>$row]);
    }

    public function update(Request $req, $id)
    {
        $row = Registration::find($id);
        if (!$row) return response()->json(['ok'=>false,'message'=>'Not found'], 404);

        $data = $req->validate([
            'instansi'  => ['sometimes','string','max:255'],
            'pic'       => ['sometimes','string','max:255'],
            'jabatan'   => ['sometimes','nullable','string','max:255'],
            'email'     => ['sometimes','email','max:255'],
            'wa'        => ['sometimes','string','max:50'],
            'alamat'    => ['sometimes','string'],
            'kelurahan' => ['sometimes','nullable','string','max:255'],
            'kecamatan' => ['sometimes','nullable','string','max:255'],
            'kota'      => ['sometimes','string','max:255'],
            'provinsi'  => ['sometimes','string','max:255'],
            'kodepos'   => ['sometimes','nullable','string','max:20'],
            'lat'       => ['sometimes','nullable','numeric','between:-90,90'],
            'lng'       => ['sometimes','nullable','numeric','between:-180,180'],
            'catatan'   => ['sometimes','nullable','string'],
            'meta'      => ['sometimes','array'],
            // status optional untuk pipeline (new/contacted/confirmed/shipped)
            'status'    => ['sometimes','in:new,possible_duplicate,duplicate,contacted,confirmed,shipped'],
            // is_primary bisa diatur lewat endpoint ini (hati-hati)
            'is_primary'=> ['sometimes','boolean'],
        ]);

        // Simpan perubahan
        $row->fill($data);
        $row->save();

        return response()->json(['ok'=>true,'data'=>$row]);
    }

    public function destroy($id)
    {
        $row = Registration::find($id);
        if (!$row) return response()->json(['ok'=>false,'message'=>'Not found'], 404);

        $row->delete(); // soft delete (pastikan model pakai SoftDeletes)
        return response()->json(['ok'=>true]);
    }
}
