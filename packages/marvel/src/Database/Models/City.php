<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    /** City activation states (drive serviceability platform-wide). */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_MAINTENANCE,
        self::STATUS_DISABLED,
    ];

    protected $table = 'cities';

    public $guarded = [];

    protected $casts = [
        'is_serviceable' => 'boolean',
        'settings'       => 'array',
        'lat'            => 'float',
        'lng'            => 'float',
        'display_order'  => 'integer',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'city_id');
    }

    /**
     * Can this city currently take orders? ACTIVE only.
     *
     * MAINTENANCE used to slip through here, which made it purely cosmetic —
     * the maintenance screen showed while orders kept flowing underneath.
     * Policy decision (2026-08): maintenance is a full stop, exactly like
     * paused/disabled, and the storefront takes over with the city's
     * configured maintenance screen (settings.maintenance). Every enforcement
     * site inherits this through ServiceAvailabilityService Tier 2 — the
     * blocked reason becomes `city_maintenance` with no further changes.
     */
    public function acceptsOrders(): bool
    {
        return $this->is_serviceable
            && $this->status === self::STATUS_ACTIVE;
    }
}
