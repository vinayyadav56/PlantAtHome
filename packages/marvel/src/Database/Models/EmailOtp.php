<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class EmailOtp extends Model
{
    protected $table = 'email_otps';

    protected $fillable = ['email', 'code_hash', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
