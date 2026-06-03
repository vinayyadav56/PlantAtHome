<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;

/**
 * Creates one demo bundle product on the live catalog so the bundle feature is
 * visible end-to-end (storefront "what's inside" + offer price + Save badge).
 * Idempotent (updateOrCreate on slug); fully removable with --remove. The
 * merchant can edit/delete it in admin.
 *
 *   php artisan plantathome:create-demo-bundle
 *   php artisan plantathome:create-demo-bundle --remove
 */
class CreateDemoBundleCommand extends Command
{
    protected $signature = 'plantathome:create-demo-bundle {--remove : Delete the demo bundle instead}';

    protected $description = 'Create (or remove) one demo bundle of air-purifying plants at an offer price.';

    private const SLUG = 'air-purifying-starter-trio';

    /** Preferred children by name; falls back to any 3 plants if missing. */
    private const PREFERRED = ['Snake Plant', 'Money Plant', 'Peace Lily', 'Pothos', 'Areca Palm', 'Spider Plant'];

    public function handle(): int
    {
        $type = Type::where('slug', 'plants')->where('language', 'en')->first();
        if (!$type) {
            $this->error('Plants type not found.');
            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $existing = Product::where('slug', self::SLUG)->where('language', 'en')->first();
            if ($existing) {
                $existing->delete(); // product_inclusions cascade on parent_id
                $this->info('Removed demo bundle.');
            } else {
                $this->warn('No demo bundle to remove.');
            }
            return self::SUCCESS;
        }

        $shopId = Product::where('type_id', $type->id)->whereNotNull('shop_id')->value('shop_id');

        // Pick up to 3 real plant children (prefer air-purifiers; never the bundle itself).
        $children = collect();
        foreach (self::PREFERRED as $name) {
            $c = Product::where('type_id', $type->id)->where('language', 'en')
                ->where('slug', '!=', self::SLUG)
                ->where('name', 'like', $name)->first();
            if ($c && !$children->contains('id', $c->id)) {
                $children->push($c);
            }
            if ($children->count() >= 3) {
                break;
            }
        }
        if ($children->count() < 3) {
            $extra = Product::where('type_id', $type->id)->where('language', 'en')
                ->where('slug', '!=', self::SLUG)
                ->whereNotIn('id', $children->pluck('id'))
                ->orderBy('id')->limit(3 - $children->count())->get();
            $children = $children->concat($extra);
        }
        if ($children->count() < 3) {
            $this->error('Not enough plant products to build a bundle.');
            return self::FAILURE;
        }

        $total = (int) $children->sum(fn ($c) => $this->representativePrice($c));
        $offer = $this->round9($total * 0.85);
        $image = $children->first()->image;

        $bundle = Product::updateOrCreate(
            ['slug' => self::SLUG, 'language' => 'en'],
            [
                'name'         => 'Air-Purifying Starter Trio',
                'description'  => 'Three easy, air-purifying plants to green your home — bundled at a better price than buying them apart.',
                'type_id'      => $type->id,
                'shop_id'      => $shopId,
                'product_type' => 'bundle',
                'status'       => 'publish',
                'visibility'   => 'visibility_public',
                'in_stock'     => true,
                'is_taxable'   => false,
                'unit'         => '3 Plants',
                'price'        => $offer,
                'sale_price'   => null,
                'min_price'    => $offer,
                'max_price'    => $offer,
                'quantity'     => 25,
                'image'        => $image,
                'gallery'      => null,
            ]
        );

        $bundle->syncInclusions(
            $children->map(fn ($c) => ['id' => $c->id, 'quantity' => 1])->all(),
            []
        );

        $this->bustProductCache();
        $this->info(sprintf(
            'Demo bundle "%s" ready: %s | total ₹%d → offer ₹%d (save ₹%d).',
            $bundle->name,
            $children->pluck('name')->implode(' + '),
            $total,
            $offer,
            $total - $offer
        ));
        return self::SUCCESS;
    }

    /**
     * A child's value as the bundle total counts it — must match
     * Product::getBundleTotalValueAttribute (sale ?: price ?: min_price), i.e.
     * the cheapest/Small size for a variable plant — so the offer price is
     * always below the displayed total value (a real saving, not a loss).
     */
    private function representativePrice(Product $c): float
    {
        return (float) ($c->sale_price ?: $c->price ?: $c->min_price ?: 0);
    }

    private function round9(float $p): int
    {
        return (int) max(9, ((int) round($p / 10) * 10) - 1);
    }

    private function bustProductCache(): void
    {
        \Illuminate\Support\Facades\Cache::forever('products:ver', (int) \Illuminate\Support\Facades\Cache::get('products:ver', 1) + 1);
    }
}
