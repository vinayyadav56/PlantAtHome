<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

/** A delivery quote we fetched from a partner (audit + book-against-quote + analytics). */
class DeliveryQuote extends Model
{
    protected $table = 'delivery_quotes';

    public $guarded = [];

    protected $casts = [
        'cod'         => 'boolean',
        'cod_amount'  => 'float',
        'quoted_cost' => 'float',
        'weight_g'    => 'integer',
        'raw'         => 'array',
        'expires_at'  => 'datetime',
    ];
}
