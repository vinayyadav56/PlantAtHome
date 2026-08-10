<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A partner order created via the courier test console (or manually recorded), so it can be listed
 * and re-tracked from the Partner Orders admin page. See PartnerConsoleOrderController +
 * CourierPartnerProxyController::testBook.
 */
class PartnerConsoleOrder extends Model
{
    protected $fillable = [
        'partner_code',
        'provider_order_id',
        'mode',
        'cod_amount_paise',
        'request',
        'response',
        'last_status',
        'last_tracked_at',
        'created_by',
    ];

    protected $casts = [
        'request'          => 'array',
        'response'         => 'array',
        'last_tracked_at'  => 'datetime',
        'cod_amount_paise' => 'integer',
    ];
}
