<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Registration extends Model
{
    use HasFactory;

    protected $fillable = [
        'instansi','pic','jabatan','email','wa',
        'alamat','kelurahan','kecamatan','kota','provinsi','kodepos',
        'lat','lng','catatan',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
    ];
}
