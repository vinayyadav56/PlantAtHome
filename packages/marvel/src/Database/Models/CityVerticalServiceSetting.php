<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operations Control Center — a city × vertical override (Tier 3). Only stored
 * when an admin deviates from inherited (city + global) status; absence ⇒ inherit.
 */
class CityVerticalServiceSetting extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_COMING_SOON = 'coming_soon';
    public const STATUSES = ['active', 'inactive', 'maintenance', 'coming_soon'];

    /** Statuses that make a vertical unavailable in the city. */
    public const BLOCKING = ['inactive', 'maintenance', 'coming_soon'];

    protected $table = 'city_vertical_service_settings';

    public $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
