<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visitor extends Model
{
    protected $table = 'visitors';

    public $guarded = [];

    protected $casts = [
        'page_views' => 'integer',
        'first_seen' => 'datetime',
        'last_seen'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
