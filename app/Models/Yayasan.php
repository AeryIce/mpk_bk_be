<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Yayasan extends Model
{
    protected $table = 'yayasan';
    public $incrementing = false;      // primary key string
    protected $keyType = 'string';
    protected $fillable = ['id','name'];

    public function sekolah()
    {
        return $this->hasMany(Sekolah::class, 'yayasan_id', 'id');
    }
}
