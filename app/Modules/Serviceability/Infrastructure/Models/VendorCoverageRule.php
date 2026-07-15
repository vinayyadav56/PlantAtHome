<?php

namespace App\Modules\Serviceability\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A vendor's declared coverage rule over the geo master. The projection
 * resolves rules in ASCENDING priority state → district → city → include,
 * then exclude removes pins outright.
 *
 * @property int         $id
 * @property int         $shop_id
 * @property string      $rule_type
 * @property int|null    $state_id
 * @property int|null    $district_id
 * @property int|null    $city_id
 * @property string|null $pincode
 * @property string      $target_key
 * @property bool        $is_active
 */
class VendorCoverageRule extends Model
{
    public const TYPE_STATE = 'state';
    public const TYPE_DISTRICT = 'district';
    public const TYPE_CITY = 'city';
    public const TYPE_PINCODE_INCLUDE = 'pincode_include';
    public const TYPE_PINCODE_EXCLUDE = 'pincode_exclude';

    /** Ascending projection priority (exclude always wins by removing pins). */
    public const RULE_TYPES = [
        self::TYPE_STATE,
        self::TYPE_DISTRICT,
        self::TYPE_CITY,
        self::TYPE_PINCODE_INCLUDE,
        self::TYPE_PINCODE_EXCLUDE,
    ];

    protected $table = 'vendor_coverage_rules';

    protected $fillable = [
        'shop_id', 'rule_type', 'state_id', 'district_id', 'city_id',
        'pincode', 'target_key', 'is_active', 'created_by', 'meta',
    ];

    protected $casts = ['is_active' => 'boolean', 'meta' => 'array'];

    /** Natural dedupe key for a rule: "{type}:{id-or-pin}". */
    public static function targetKey(string $type, int|string $idOrPin): string
    {
        return $type.':'.$idOrPin;
    }
}
