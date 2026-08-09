<?php

namespace Marvel\Database\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marvel\Traits\TranslationTrait;

class Coupon extends Model
{
    use SoftDeletes;
    use TranslationTrait;

    protected $table = 'coupons';

    public $guarded = [];

    // protected $appends = ['is_valid'];
    protected $appends = ['is_valid', 'translated_languages'];

    protected $casts = [
        'image'                => 'json',
        'usage_limit'          => 'integer',
        'usage_limit_per_user' => 'integer',
        'times_used'           => 'integer',
    ];

    /** Redemptions of this coupon (one row per order). */
    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class, 'coupon_id');
    }

    /**
     * Cheap, advisory check for the checkout preview + a fail-fast gate before order
     * creation. The AUTHORITATIVE, race-safe enforcement is CouponRepository::consume(),
     * which runs under a row lock inside the order transaction — this is only UX so a
     * clearly-exhausted coupon is rejected before we build the whole order.
     */
    public function isExhausted(?int $userId = null): bool
    {
        if ($this->usage_limit !== null && (int) $this->times_used >= (int) $this->usage_limit) {
            return true;
        }
        if ($this->usage_limit_per_user !== null && $userId) {
            $used = $this->usages()->where('user_id', $userId)->count();
            if ($used >= (int) $this->usage_limit_per_user) {
                return true;
            }
        }
        return false;
    }

    protected static function boot()
    {
        parent::boot();
        // Order by updated_at desc
        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('updated_at', 'desc');
        });
    }

    /**
     * @return HasMany
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'coupon_id');
    }

    /**
     * @return bool
     */
    public function getIsValidAttribute()
    {
        return Carbon::now()->between($this->active_from, $this->expire_at);
    }
        /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
