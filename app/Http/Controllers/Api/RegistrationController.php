<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RegistrationController extends Controller
{
    public function store(Request $request)
    {
        // ——— VALIDATION ———
        $validated = $request->validate([
            'instansi'  => 'required|string|max:150',
            'pic'       => 'required|string|max:100',
            'jabatan'   => 'nullable|string|max:100',
            'email'     => 'required|email:rfc,dns|max:120',
            'wa'        => 'required|string|max:25',
            'alamat'    => 'required|string|max:200',
            'kelurahan' => 'nullable|string|max:80',
            'kecamatan' => 'nullable|string|max:80',
            'kota'      => 'required|string|max:80',
            'provinsi'  => 'required|string|max:80',
            'kodepos'   => 'nullable|string|max:15',
            'lat'       => 'nullable|numeric',
            'lng'       => 'nullable|numeric',
            'catatan'   => 'nullable|string|max:500',

            'meta'            => 'nullable|array',
            'meta.path'       => 'nullable|in:yayasan,sekolah,perusahaan',
            'meta.yayasanId'  => 'required_if:meta.path,yayasan,sekolah|integer',
            'meta.sekolahId'  => 'required_if:meta.path,sekolah|integer',
        ]);

        // ——— IDEMPOTENCY 10 menit (email+wa+instansi) ———
        $window = Carbon::now()->subMinutes(10);
        $dup = Registration::query()
            ->where('email', $validated['email'])
            ->where('wa', $validated['wa'])
            ->where('instansi', $validated['instansi'])
            ->where('created_at', '>=', $window)
            ->first();

        if ($dup) {
            return response()->json([
                'ok'   => true,
                'id'   => null, // duplicate suppressed
                'msg'  => 'Terima kasih! Data sudah kami terima.',
                'note' => 'duplicate_within_10min',
            ], 201);
        }

        // ——— INSERT aman tanpa mass-assignment ———
        $reg = new Registration();
        $reg->instansi  = $validated['instansi'];
        $reg->pic       = $validated['pic'];
        $reg->jabatan   = $validated['jabatan']   ?? null;
        $reg->email     = $validated['email'];
        $reg->wa        = $validated['wa'];
        $reg->alamat    = $validated['alamat'];
        $reg->kelurahan = $validated['kelurahan'] ?? null;
        $reg->kecamatan = $validated['kecamatan'] ?? null;
        $reg->kota      = $validated['kota'];
        $reg->provinsi  = $validated['provinsi'];
        $reg->kodepos   = $validated['kodepos']   ?? null;
        $reg->lat       = $validated['lat']       ?? null;
        $reg->lng       = $validated['lng']       ?? null;
        $reg->catatan   = $validated['catatan']   ?? null;

        // Jika tabel registrations punya kolom relasi, isi aman (abaikan kalau tidak ada)
        if (isset($validated['meta']['yayasanId']) && property_exists($reg, 'yayasan_id')) {
            $reg->yayasan_id = (int) $validated['meta']['yayasanId'];
        }
        if (isset($validated['meta']['sekolahId']) && property_exists($reg, 'sekolah_id')) {
            $reg->sekolah_id = (int) $validated['meta']['sekolahId'];
        }

        $reg->save();

        return response()->json([
            'ok'  => true,
            'id'  => $reg->id,
            'msg' => 'Terima kasih! Data berhasil dikirim.',
        ], 201);
    }
}
