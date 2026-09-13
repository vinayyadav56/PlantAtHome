<?php

namespace Marvel\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which commission rule applies to a line (spec §12): product > category > vendor rule
 * (active, effective on the date; higher priority wins, then the newest) > the shop's own
 * mode — `commission` uses the legacy balances.admin_commission_rate, `cost_sheet` (D1) needs
 * no rate. The result is FROZEN onto the line at recognition; rule edits never move history.
 */
class VendorCommissionRuleResolver
{
    public const PERCENTAGE = 'percentage';
    public const FIXED_PER_ITEM = 'fixed_per_item';
    public const FIXED_PER_ORDER = 'fixed_per_order';
    public const COST_SHEET = 'cost_sheet';
    public const MODES = [self::PERCENTAGE, self::FIXED_PER_ITEM, self::FIXED_PER_ORDER, self::COST_SHEET];

    private static ?bool $hasRules = null;

    /** @return array{mode:string, value:string, rule_id:?int, scope:string} */
    public function resolve(int $shopId, ?int $productId, array $categoryIds = [], ?string $shopMode = null, ?Carbon $at = null): array
    {
        $at = ($at ?: Carbon::today())->toDateString();
        if (self::$hasRules ??= Schema::hasTable('vendor_commission_rules')) {
            $rules = DB::table('vendor_commission_rules')->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $at))
                ->where(fn ($q) => $q->whereNull('shop_id')->orWhere('shop_id', $shopId))
                ->where(function ($q) use ($productId, $categoryIds, $shopId) {
                    $q->where(fn ($w) => $w->where('scope', 'product')->where('product_id', $productId ?? -1));
                    if ($categoryIds) {
                        $q->orWhere(fn ($w) => $w->where('scope', 'category')->whereIn('category_id', $categoryIds));
                    }
                    $q->orWhere(fn ($w) => $w->where('scope', 'vendor')->where('shop_id', $shopId));
                })
                ->orderByRaw("CASE scope WHEN 'product' THEN 0 WHEN 'category' THEN 1 ELSE 2 END")
                ->orderByDesc('priority')->orderByDesc('effective_from')->orderByDesc('id')
                ->first();
            if ($rules) {
                return ['mode' => $rules->mode, 'value' => number_format((float) $rules->value, 4, '.', ''), 'rule_id' => (int) $rules->id, 'scope' => $rules->scope];
            }
        }
        if (($shopMode ?: self::COST_SHEET) === VendorPayableCalculator::COMMISSION) {
            return ['mode' => self::PERCENTAGE, 'value' => $this->legacyRate($shopId), 'rule_id' => null, 'scope' => 'legacy'];
        }
        return ['mode' => self::COST_SHEET, 'value' => '0.0000', 'rule_id' => null, 'scope' => 'shop_default'];
    }

    /** Category ids of a product (guarded: the pivot may be absent in a stub schema). */
    public function categoryIdsFor(?int $productId): array
    {
        if (!$productId) {
            return [];
        }
        try {
            return Schema::hasTable('category_product') ? DB::table('category_product')->where('product_id', $productId)->pluck('category_id')->map(fn ($v) => (int) $v)->all() : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function legacyRate(int $shopId): string
    {
        try {
            return number_format((float) (DB::table('balances')->where('shop_id', $shopId)->value('admin_commission_rate') ?? 0), 4, '.', '');
        } catch (\Throwable $e) {
            return '0.0000';
        }
    }

    /** @internal tests */
    public static function resetSchemaMemo(): void
    {
        self::$hasRules = null;
    }
}
