<?php

namespace Marvel\Database\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    use Sluggable;

    protected $table = 'shops';

    public $guarded = [];

    protected $casts = [
        'logo' => 'json',
        'cover_image' => 'json',
        'address' => 'json',
        'settings' => 'json',
        'self_delivery' => 'json',
        'documents_due_at' => 'datetime',
        'kyc_reminded_at' => 'datetime',
        'vendor_rating' => 'float',
        'vendor_rating_count' => 'integer',
        'vendor_priority_score' => 'integer',
        'sla_default_days' => 'integer',
    ];

    /**
     * Return the sluggable configuration array for this model.
     *
     * @return array
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name'
            ]
        ];
    }

    /** The master PlantAtHome shop's canonical slug (single-shop model). */
    public const MASTER_SLUG = 'plantathome';

    /**
     * `approval_status` values. Plain varchar, no DB constraint.
     *
     * ON_HOLD is distinct from 'rejected': the vendor missed the KYC deadline
     * and is suspended until the documents arrive, whereas rejected is a
     * decision. Only ON_HOLD is enforced on the selling path — see
     * AvailabilityService::excludeHeldVendors().
     */
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ON_HOLD  = 'on_hold';

    /**
     * Per-process cache for masterId(). A class-level static, not a function-local one, so a
     * test can reset it explicitly — see resetMasterIdCache(). A function-local `static $id`
     * would resolve to the SAME correctness in production (one real HTTP request = one fresh PHP
     * execution context, so it was already effectively "per request"), but PHPUnit runs every
     * test in one file, and every file in one suite, inside a SINGLE PHP process — so whichever
     * test first calls masterId() poisons the cache for every test that runs after it, in any
     * file, for the rest of that run. Found live: a new resettable regression test for the
     * review pipeline (VendorCatalogAvailabilityTest) passed in isolation and failed only when
     * the full suite ran, because an unrelated, earlier-running test had already cached a
     * different shop's id as "the master shop".
     */
    private static ?int $masterIdCache = null;

    /**
     * Single-shop model: every catalog product belongs to THE master PlantAtHome
     * shop; vendor shops are pure suppliers (inventory + service areas only).
     * Find-or-create keeps fresh installs working; cached for the life of the process.
     */
    public static function masterId(): int
    {
        if (static::$masterIdCache !== null) {
            return static::$masterIdCache;
        }
        $shop = static::where('slug', self::MASTER_SLUG)->first();
        if (!$shop) {
            $ownerId = User::whereHas(
                'permissions',
                fn ($q) => $q->where('name', \Marvel\Enums\Permission::SUPER_ADMIN)
            )->value('id');
            $shop = static::create([
                'name'      => 'PlantAtHome',
                'slug'      => self::MASTER_SLUG,
                'owner_id'  => $ownerId,
                'is_active' => true,
                'settings'  => ['is_master' => true],
            ]);
        }
        return static::$masterIdCache = (int) $shop->id;
    }

    /** Test-only: clears the per-process masterId() cache so a fresh fixture is re-read. */
    public static function resetMasterIdCache(): void
    {
        static::$masterIdCache = null;
    }

    /**
     * @return HasOne
     */
    public function balance(): HasOne
    {
        return $this->hasOne(Balance::class, 'shop_id');
    }

    /**
     * @return BelongsTo
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'shop_id');
    }

    /**
     * @return HasMany
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class, 'shop_id');
    }

    /**
     * @return HasMany
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'shop_id');
    }

    /** Cities this vendor serves + how (local/courier). Marketplace city-availability. */
    public function serviceAreas(): HasMany
    {
        return $this->hasMany(VendorServiceArea::class, 'shop_id');
    }

    /** This vendor's inventory mapping onto master products (cost/stock/availability). */
    public function vendorProductPrices(): HasMany
    {
        return $this->hasMany(VendorProductPrice::class, 'shop_id');
    }

    /** This vendor's shipping cost + SLA by zone (assignment score + checkout estimate). */
    public function shippingRates(): HasMany
    {
        return $this->hasMany(VendorShippingRate::class, 'shop_id');
    }

    /**
     * @return HasMany
     */
    public function withdraws(): HasMany
    {
        return $this->hasMany(Withdraw::class, 'shop_id');
    }

    /**
     * @return HasMany
     */
    public function staffs(): HasMany
    {
        return $this->hasMany(User::class, 'shop_id');
    }

    /**
     * @return HasMany
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(User::class, 'shop_id');
    }

    /**
     * @return BelongsToMany
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_shop');
    }

    /**
     * @return BelongsToMany
     */
    public function users(): BelongsToMany
    {
        return $this->BelongsToMany(User::class, 'user_shop');
    }

    /**
     * @return HasMany
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'shop_id');
    }

    /**
     * faqs
     *
     * @return HasMany
     */
    public function faqs(): HasMany
    {
        return $this->HasMany(Faqs::class);
    }

    /**
     * terms and conditions
     *
     * @return HasMany
     */
    public function terms_and_conditions(): HasMany
    {
        return $this->HasMany(TermsAndConditions::class);
    }
    /**
     * faqs
     *
     * @return HasMany
     */
    public function coupons(): HasMany
    {
        return $this->HasMany(Coupon::class);
    }
    /**
     * ownership transfers
     *
     * @return HasOne
     */
    public function ownership_history(): HasOne
    {
        return $this->hasOne(OwnershipTransfer::class, 'shop_id');
    }
}
