<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Registration extends Model
{
    use SoftDeletes; // kalau tabelmu pakai soft deletes; kalau belum, hapus baris ini

    protected $table = 'registrations';

    protected $fillable = [
        'instansi','pic','jabatan',
        'email','wa',
        'alamat','kelurahan','kecamatan','kota','provinsi','kodepos',
        'lat','lng','catatan',
        // kolom anti-dupe:
        'dup_group_id','dup_score','dup_reason','is_primary','status',
        // json meta:
        'meta',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'is_primary' => 'boolean',
        'dup_score' => 'integer',
        'meta' => 'array',   // penting: biar array <-> json otomatis
    ];
}
