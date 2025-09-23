<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MagicLink extends Model
{
    use HasFactory;

    protected $table = 'magic_links';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'email',
        'token',       // legacy (akan di-null-kan)
        'token_hash',  // new
        'purpose',
        'expires_at',
        'used_at',
        'meta',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'meta'       => 'array',
    ];
}
