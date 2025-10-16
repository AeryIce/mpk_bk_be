<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Registration;
use Illuminate\Support\Facades\Log;            // ✅ pakai Log facade
use Illuminate\Validation\ValidationException; 

class RegistrationController extends Controller
{
    public function store(Request $request)
    {
        // 1) Validasi input
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

                // meta dari FE (opsional) – untuk guard
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

        // 2) Guard ringan berbasis path (kalau FE mengirimkannya)
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

        // 3) Hard guard relasi: sekolah harus milik yayasan yang dipilih
        if ($yayasanId && $sekolahId) {
            $belongsTo = DB::table('sekolah')->where('id', $sekolahId)->value('yayasan_id');
            if (!$belongsTo || (string)$belongsTo !== (string)$yayasanId) {
                return response()->json([
                    'message' => 'Sekolah tidak sesuai dengan yayasan yang dipilih.',
                    'errors'  => ['meta.sekolahId' => ['Sekolah tidak match dengan Yayasan.']],
                ], 422);
            }
        }

        // 4) Simpan — tetap via Model (tidak mengubah prosesmu)
        try {
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

            // pastikan model Registration punya $fillable sesuai kolom di atas
            $reg = Registration::create($payload);

            return response()->json([
                'ok'  => true,
                'id'  => $reg->id,
                'msg' => 'Terima kasih! Data berhasil dikirim.',
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[REG][STORE] '.$e->getMessage(), ['file'=>$e->getFile(),'line'=>$e->getLine()]);
            return response()->json([
                'ok'      => false,
                'message' => 'Gagal menyimpan pendaftaran.',
            ], 500);
        }
    }
}
