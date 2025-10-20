<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema; // ✅ import Schema
use Illuminate\Support\Str;            // ✅ import Str

class RegistrationAdminController extends Controller
{
    public function index(Request $req)
    {
        $q = trim((string) $req->query('search', ''));
        $per = max(1, min((int)$req->query('perPage', 20), 200));

        $rows = Registration::query()
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    // ILIKE for Postgres (case-insensitive)
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

    /**
     * Admin Create (seed minimal data)
     * - Memungkinkan buat entri sekolah minimal (instansi+kota+provinsi),
     *   field lain opsional (email/wa/pic boleh kosong).
     * - Tidak mengubah endpoint publik.
     */
    public function store(Request $req)
    {
        $data = $req->validate([
            'instansi'  => ['required','string','max:255'],
            'kota'      => ['required','string','max:255'],
            'provinsi'  => ['required','string','max:255'],

            'pic'       => ['sometimes','nullable','string','max:255'],
            'jabatan'   => ['sometimes','nullable','string','max:255'],
            'email'     => ['sometimes','nullable','email','max:255'],
            'wa'        => ['sometimes','nullable','string','max:50'],

            'alamat'    => ['sometimes','nullable','string'],
            'kelurahan' => ['sometimes','nullable','string','max:255'],
            'kecamatan' => ['sometimes','nullable','string','max:255'],
            'kodepos'   => ['sometimes','nullable','string','max:20'],

            'lat'       => ['sometimes','nullable','numeric','between:-90,90'],
            'lng'       => ['sometimes','nullable','numeric','between:-180,180'],
            'catatan'   => ['sometimes','nullable','string'],
            'meta'      => ['sometimes','array'],

            // optional pipeline
            'status'    => ['sometimes','in:new,possible_duplicate,duplicate,contacted,confirmed,shipped,seeded'],
            'is_primary'=> ['sometimes','boolean'],
        ]);

        $payload = [
            'instansi'  => $data['instansi'],
            'kota'      => $data['kota'],
            'provinsi'  => $data['provinsi'],
            'pic'       => $data['pic']       ?? null,
            'jabatan'   => $data['jabatan']   ?? null,
            'email'     => $data['email']     ?? null,
            'wa'        => $data['wa']        ?? null,
            'alamat'    => $data['alamat']    ?? null,
            'kelurahan' => $data['kelurahan'] ?? null,
            'kecamatan' => $data['kecamatan'] ?? null,
            'kodepos'   => $data['kodepos']   ?? null,
            'lat'       => $data['lat']       ?? null,
            'lng'       => $data['lng']       ?? null,
            'catatan'   => $data['catatan']   ?? null,
        ];

        // Gate by schema
        $hasDupCols = Schema::hasColumn('registrations','dup_group_id')
            && Schema::hasColumn('registrations','dup_score')
            && Schema::hasColumn('registrations','dup_reason')
            && Schema::hasColumn('registrations','is_primary')
            && Schema::hasColumn('registrations','status');
        $hasMetaCol = Schema::hasColumn('registrations','meta');

        if ($hasDupCols) {
            $payload['dup_group_id'] = (string) Str::uuid();
            $payload['dup_score']    = 0;
            $payload['dup_reason']   = 'seeded';
            $payload['is_primary']   = $data['is_primary'] ?? true;
            $payload['status']       = $data['status'] ?? 'seeded';
        }

        if ($hasMetaCol && array_key_exists('meta', $data)) {
            $payload['meta'] = $data['meta'];
        }

        $row = Registration::create($payload);

        return response()->json(['ok'=>true,'data'=>$row], 201);
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
            'status'    => ['sometimes','in:new,possible_duplicate,duplicate,contacted,confirmed,shipped,seeded'],
            'is_primary'=> ['sometimes','boolean'],
        ]);

        $row->fill($data);
        $row->save();

        return response()->json(['ok'=>true,'data'=>$row]);
    }

    public function destroy($id)
    {
        $row = Registration::find($id);
        if (!$row) return response()->json(['ok'=>false,'message'=>'Not found'], 404);

        $row->delete(); // soft delete (pastikan model pakai SoftDeletes jika perlu)
        return response()->json(['ok'=>true]);
    }
}
