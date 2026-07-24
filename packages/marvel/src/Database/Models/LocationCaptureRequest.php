<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One admin-initiated location-capture email + its outcome (audit row). */
class LocationCaptureRequest extends Model
{
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_VENDOR   = 'vendor';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_EXPIRED    = 'expired';
    public const STATUS_SUPERSEDED = 'superseded'; // regenerated before use

    protected $table = 'location_capture_requests';

    public $guarded = [];

    // The token hash never leaves the API.
    protected $hidden = ['token_hash'];

    protected $casts = [
        'latitude'     => 'float',
        'longitude'    => 'float',
        'accuracy'     => 'float',
        'opened_at'    => 'datetime',
        'expires_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'vendor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
