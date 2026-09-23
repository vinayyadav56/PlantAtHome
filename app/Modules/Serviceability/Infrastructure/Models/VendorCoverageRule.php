<?php

namespace App\Modules\Serviceability\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A vendor's declared coverage rule over the geo master. The projection
 * resolves rules in ASCENDING priority state → district → city → include,
 * then the excludes remove pins outright (whole district, whole city, or one
 * pin at a time).
 *
 * Rules are scoped to a `vertical` (a Type slug). '*' means "every vertical";
 * once a vendor writes any rule for vertical X, X projects from X's rules
 * ALONE — a named vertical replaces the default rather than adding to it.
 *
 * @property int         $id
 * @property int         $shop_id
 * @property string      $rule_type
 * @property string      $vertical
 * @property int|null    $state_id
 * @property int|null    $district_id
 * @property int|null    $city_id
 * @property string|null $pincode
 * @property string      $target_key
 * @property bool        $is_active
 * @property string|null $fulfillment_mode
 * @property int|null    $eta_days
 */
class VendorCoverageRule extends Model
{
    public const TYPE_STATE = 'state';
    public const TYPE_DISTRICT = 'district';
    public const TYPE_CITY = 'city';
    public const TYPE_PINCODE_INCLUDE = 'pincode_include';
    public const TYPE_PINCODE_EXCLUDE = 'pincode_exclude';
    public const TYPE_DISTRICT_EXCLUDE = 'district_exclude';
    public const TYPE_CITY_EXCLUDE = 'city_exclude';

    /** Every vertical — the default scope, never NULL (a sentinel keeps target_key unique). */
    public const VERTICAL_ALL = '*';

    /** Ascending projection priority (the excludes always win by removing pins). */
    public const RULE_TYPES = [
        self::TYPE_STATE,
        self::TYPE_DISTRICT,
        self::TYPE_CITY,
        self::TYPE_PINCODE_INCLUDE,
        self::TYPE_PINCODE_EXCLUDE,
        self::TYPE_DISTRICT_EXCLUDE,
        self::TYPE_CITY_EXCLUDE,
    ];

    /** Rule types that remove coverage rather than granting it. */
    public const EXCLUDE_TYPES = [
        self::TYPE_PINCODE_EXCLUDE,
        self::TYPE_DISTRICT_EXCLUDE,
        self::TYPE_CITY_EXCLUDE,
    ];

    /** The delivery promise a rule may carry down to its pins. */
    public const MODES = ['local', 'courier', 'both'];

    protected $table = 'vendor_coverage_rules';

    protected $fillable = [
        'shop_id', 'rule_type', 'vertical', 'state_id', 'district_id', 'city_id',
        'pincode', 'target_key', 'is_active', 'created_by', 'meta',
        'fulfillment_mode', 'eta_days',
    ];

    protected $casts = ['is_active' => 'boolean', 'meta' => 'array'];

    /**
     * Natural dedupe key for a rule: "{type}:{id-or-pin}", with "@{vertical}"
     * appended for a named vertical so '*' rules keep the keys they were
     * written with.
     */
    public static function targetKey(string $type, int|string $idOrPin, string $vertical = self::VERTICAL_ALL): string
    {
        $key = $type.':'.$idOrPin;

        return $vertical === self::VERTICAL_ALL ? $key : $key.'@'.$vertical;
    }

    /** '*' for anything empty — the column is NOT NULL by design. */
    public static function normalizeVertical(?string $vertical): string
    {
        $vertical = trim((string) $vertical);

        return $vertical === '' ? self::VERTICAL_ALL : $vertical;
    }
}
