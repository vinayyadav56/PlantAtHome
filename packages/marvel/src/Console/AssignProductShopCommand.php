<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;

/**
 * Ensures a single "PlantAtHome" shop exists (owned by the admin) and assigns
 * every product that has no shop to it. The bulk plant/tools/farmbox seeders
 * create products with shop_id = null, which makes them fail the admin save
 * validation (shop_id is required + must exist). Run this after the seeders.
 *
 *   php artisan plantathome:assign-shop
 *
 * Idempotent and safe to re-run.
 */
class AssignProductShopCommand extends Command
{
    protected $signature = 'plantathome:assign-shop';

    protected $description = 'Ensure the PlantAtHome shop exists and assign shop-less products to it.';

    public function handle(): int
    {
        $owner = User::where('email', env('ADMIN_EMAIL', config('mail.from.address')))->first()
            ?? User::first();

        $shop = Shop::firstOrCreate(
            ['slug' => 'plantathome'],
            [
                'name'      => 'PlantAtHome',
                'owner_id'  => optional($owner)->id ?? 1,
                'is_active' => 1,
            ]
        );

        // make sure it's active + owned even if it already existed
        $shop->forceFill([
            'is_active' => 1,
            'owner_id'  => $shop->owner_id ?: (optional($owner)->id ?? 1),
        ])->save();

        $count = Product::whereNull('shop_id')->update(['shop_id' => $shop->id]);

        $this->info("PlantAtHome shop #{$shop->id} ready. Assigned {$count} shop-less product(s).");

        return self::SUCCESS;
    }
}
