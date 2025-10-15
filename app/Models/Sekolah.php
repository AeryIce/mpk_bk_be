<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sekolah extends Model
{
    protected $table = 'sekolah';
    public $incrementing = false;      // primary key string (NPSN / fallback)
    protected $keyType = 'string';
    protected $fillable = [
        'id','yayasan_id','name','jenjang','kecamatan','kabupaten','provinsi','npsn'
    ];

    public function yayasan()
    {
        return $this->belongsTo(Yayasan::class, 'yayasan_id', 'id');
    }
}
