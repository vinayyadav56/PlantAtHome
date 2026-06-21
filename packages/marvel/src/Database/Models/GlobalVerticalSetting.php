<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operations Control Center — global master switch for a vertical (Tier 1).
 * The reserved slug `__platform__` carries platform-wide emergency flags in
 * `settings` (stop_orders, stop_deliveries, stop_platform, maintenance).
 */
class GlobalVerticalSetting extends Model
{
    public const PLATFORM_SLUG = '__platform__';

    // The two non-product (service) verticals that have no Type row.
    public const SERVICE_VERTICALS = ['garden_services', 'delivery_services'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_COMING_SOON = 'coming_soon';
    public const STATUSES = ['active', 'maintenance', 'disabled', 'coming_soon'];

    protected $table = 'global_vertical_settings';

    public $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'settings'  => 'array',
    ];
}
