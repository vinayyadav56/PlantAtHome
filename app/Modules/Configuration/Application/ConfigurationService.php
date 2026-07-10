<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Catalog\Infrastructure\Models\Product as CatalogProduct;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant as CatalogVariant;
use App\Modules\Configuration\Domain\SelectType;
use App\Modules\Configuration\Domain\ValidationResult;
use App\Modules\Configuration\Infrastructure\Models\ConfigOption;
use App\Modules\Configuration\Infrastructure\Models\NurseryEnablement;
use App\Modules\Configuration\Infrastructure\Models\OptionCompatibility;
use App\Modules\Configuration\Infrastructure\Models\ProductAssignment;
use App\Shared\Application\DomainActionException;
use App\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Facades\Cache;

/**
 * The Configuration Engine (Section 5). Given a product + variant + city +
 * nursery it resolves the groups and options to render (filtered by
 * compatibility, vendor enablement, and — later — stock and rules), validates a
 * customer's selection returning ALL violations, and freezes a selection into a
 * denormalized snapshot for cart/order.
 *
 * NOTHING is special-cased per group — every step is data-driven. Resolved
 * configurations are cached (keyed by product:variant:city:nursery) and
 * invalidated by a version bump on any configuration write.
 *
 * Reads Catalog (product/variant identity) — a sanctioned cross-context read
 * (Section 3). Inventory (Phase 5), Rules (Phase 4) and Pricing (Phase 6) hooks
 * are marked below; until those land, options are in-stock and priced at the
 * option base price.
 */
class ConfigurationService
{
    private const CACHE_TTL = 300; // seconds
    private const VERSION_KEY = 'config:cache:version';

    /**
     * Resolve the configuration to render on a product page.
     *
     * @return array{meta:array, groups:array}
     */
    public function resolveForProduct(string $productUuid, string $variantUuid, ?string $city, ?string $nurseryId): array
    {
        [$product, $variant] = $this->resolveCatalog($productUuid, $variantUuid);

        $key = sprintf(
            'config:resolve:%d:%d:%s:%s:v%d',
            $product->id,
            $variant->id,
            $city ?: '-',
            $nurseryId ?: '-',
            $this->version(),
        );

        return Cache::remember($key, self::CACHE_TTL, function () use ($product, $variant, $city, $nurseryId) {
            return [
                'meta' => [
                    'product_uuid' => $product->uuid,
                    'variant_uuid' => $variant->uuid,
                    'size_code'    => $variant->size_code,
                    'city'         => $city,
                    'nursery_id'   => $nurseryId,
                ],
                'groups' => $this->buildGroups($product->id, $variant->id, $variant->size_code, $nurseryId),
            ];
        });
    }

    /**
     * Validate a customer's chosen options. Returns ALL violations at once.
     *
     * @param  array<string, array<int,string>>  $selection  group_code => [option_uuid,...]
     */
    public function validateSelection(string $variantUuid, array $selection, ?string $city, ?string $nurseryId): ValidationResult
    {
        $variant = CatalogVariant::where('uuid', $variantUuid)->first();
        if (! $variant) {
            throw DomainActionException::notFound('Variant not found.', 'VARIANT_NOT_FOUND');
        }
        $product = CatalogProduct::find($variant->product_id);

        $resolved = $this->resolveForProduct($product->uuid, $variant->uuid, $city, $nurseryId);
        $result = new ValidationResult();

        // Index resolved groups by code, and their allowed option uuids.
        $allowed = [];   // code => [option_uuid => option]
        foreach ($resolved['groups'] as $group) {
            $allowed[$group['code']] = [
                'group'   => $group,
                'options' => array_column($group['options'], null, 'uuid'),
            ];
        }

        // 1) Selections that reference a group not assigned to this product.
        foreach ($selection as $code => $optionUuids) {
            if (! isset($allowed[$code])) {
                $result->addViolation('UNKNOWN_GROUP', "Group '{$code}' is not configurable for this product.", $code);
            }
        }

        // 2) Per assigned group: required, cardinality, and per-option validity.
        foreach ($allowed as $code => $data) {
            $group = $data['group'];
            $chosen = array_values(array_unique($selection[$code] ?? []));

            if ($group['required'] && $chosen === []) {
                $result->addViolation('REQUIRED', "Group '{$group['name']}' requires a selection.", $code);
            }

            if ($group['select_type'] === SelectType::SINGLE && count($chosen) > 1) {
                $result->addViolation('SINGLE_SELECT', "Group '{$group['name']}' allows only one selection.", $code);
            }

            foreach ($chosen as $optionUuid) {
                if (! isset($data['options'][$optionUuid])) {
                    $result->addViolation('INVALID_OPTION', "Option '{$optionUuid}' is not available for this group/size/vendor.", $code);
                }
            }
        }

        return $result;
    }

    /**
     * Freeze a selection into a fully-denormalized snapshot for cart/order.
     *
     * @param  array<string, array<int,string>>  $selection
     * @return array{items:array, total:array, total_weight_grams:int}
     */
    public function snapshot(string $variantUuid, array $selection): array
    {
        $variant = CatalogVariant::where('uuid', $variantUuid)->first();
        if (! $variant) {
            throw DomainActionException::notFound('Variant not found.', 'VARIANT_NOT_FOUND');
        }

        $items = [];
        $total = Money::zero('INR');
        $weight = 0;

        $uuids = collect($selection)->flatten()->unique()->all();
        $options = ConfigOption::with('group')->whereIn('uuid', $uuids)->get()->keyBy('uuid');

        foreach ($selection as $groupCode => $optionUuids) {
            foreach ((array) $optionUuids as $optionUuid) {
                $option = $options->get($optionUuid);
                if (! $option) {
                    continue; // snapshot only what still exists; validation gates the rest
                }
                $price = Money::fromDecimal((float) $option->base_price, $option->currency ?: 'INR');
                $total = $total->add($price);
                $weight += (int) $option->weight_grams;

                $items[] = [
                    'group_code'   => $groupCode,
                    'option_uuid'  => $option->uuid,
                    'sku'          => $option->sku,
                    'name'         => $option->name,
                    'weight_grams' => (int) $option->weight_grams,
                    'price'        => $price->toArray(),
                ];
            }
        }

        return [
            'items'              => $items,
            'total'              => $total->toArray(),
            'total_weight_grams' => $weight,
        ];
    }

    /** Bump the cache version so all resolved configurations recompute. */
    public function invalidate(): void
    {
        Cache::increment(self::VERSION_KEY);
        // increment() no-ops if the key is missing on some stores; ensure it exists.
        if (Cache::get(self::VERSION_KEY) === null) {
            Cache::forever(self::VERSION_KEY, 1);
        }
    }

    /* ── internals ─────────────────────────────────────────────────────────── */

    private function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    /** @return array{0:CatalogProduct,1:CatalogVariant} */
    private function resolveCatalog(string $productUuid, string $variantUuid): array
    {
        $product = CatalogProduct::where('uuid', $productUuid)->first();
        if (! $product) {
            throw DomainActionException::notFound('Product not found.', 'PRODUCT_NOT_FOUND');
        }
        $variant = CatalogVariant::where('uuid', $variantUuid)->where('product_id', $product->id)->first();
        if (! $variant) {
            throw DomainActionException::unprocessable('Variant does not belong to this product.', 'VARIANT_MISMATCH', 'variant');
        }

        return [$product, $variant];
    }

    /** @return array<int,array> the resolved groups with surviving options */
    private function buildGroups(int $productId, int $variantId, ?string $sizeCode, ?string $nurseryId): array
    {
        $assignments = ProductAssignment::with(['group.options.compatibility'])
            ->where('product_id', $productId)
            ->orderBy('sort')
            ->get();

        $enablement = $nurseryId
            ? NurseryEnablement::where('nursery_id', $nurseryId)->pluck('is_enabled', 'option_id')
            : collect();

        $groups = [];
        foreach ($assignments as $assignment) {
            $group = $assignment->group;
            if (! $group) {
                continue;
            }

            $options = [];
            foreach ($group->options as $option) {
                if ($option->status !== 'active') {
                    continue;
                }
                if (! $this->isCompatible($option, $variantId, $sizeCode)) {
                    continue; // step 2: compatibility
                }
                if (! $this->isEnabled($enablement, $option->id, $nurseryId)) {
                    continue; // step 3: vendor enablement
                }
                // step 4 (inventory) + step 5 (rules) hooks land in later phases.

                $price = Money::fromDecimal((float) $option->base_price, $option->currency ?: 'INR');
                $options[] = [
                    'uuid'         => $option->uuid,
                    'sku'          => $option->sku,
                    'name'         => $option->name,
                    'weight_grams' => (int) $option->weight_grams,
                    'price'        => $price->toArray(),   // step 6 (pricing) refines this later
                    'in_stock'     => true,                 // step 4 stub
                    'is_default'   => $assignment->default_option_id === $option->id,
                ];
            }

            $groups[] = [
                'uuid'        => $group->uuid,
                'code'        => $group->code,
                'name'        => $group->name,
                'select_type' => $group->select_type,
                'required'    => $assignment->is_required_override ?? $group->is_required,
                'sort'        => $assignment->sort,
                'options'     => $options,
            ];
        }

        return $groups;
    }

    /**
     * An option with no compatibility rows fits every variant. With rows, it
     * fits only where the rules say so, evaluated deterministically (never
     * order-dependent):
     *   1. variant-specific rows (variant_id match) take precedence over size
     *      rows — they describe THIS exact variant;
     *   2. within the applicable tier, an is_compatible=false row wins (an
     *      explicit exclusion is never masked by a matching true row);
     *   3. a restricted option with no rule matching this variant/size is
     *      excluded.
     */
    private function isCompatible(ConfigOption $option, int $variantId, ?string $sizeCode): bool
    {
        $rows = $option->compatibility;
        if ($rows->isEmpty()) {
            return true;
        }

        $variantRows = $rows->filter(fn (OptionCompatibility $r) => $r->variant_id !== null && (int) $r->variant_id === $variantId);
        if ($variantRows->isNotEmpty()) {
            return ! $variantRows->contains(fn (OptionCompatibility $r) => ! $r->is_compatible);
        }

        $sizeRows = $rows->filter(fn (OptionCompatibility $r) => $r->size_code !== null && $sizeCode !== null && $r->size_code === $sizeCode);
        if ($sizeRows->isNotEmpty()) {
            return ! $sizeRows->contains(fn (OptionCompatibility $r) => ! $r->is_compatible);
        }

        // Restricted option with no rule for this variant/size ⇒ excluded.
        return false;
    }

    /** Default enabled; an explicit is_enabled=false row opts the vendor out. */
    private function isEnabled($enablement, int $optionId, ?string $nurseryId): bool
    {
        if ($nurseryId === null) {
            return true; // no vendor context ⇒ show everything
        }

        return $enablement->has($optionId) ? (bool) $enablement->get($optionId) : true;
    }
}
