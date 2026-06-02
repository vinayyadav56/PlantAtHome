<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;

/**
 * Replaces the 193 granular plant categories with ~10 curated "type" categories
 * (Indoor, Outdoor, Flowering, …) and re-assigns every plant to the matching
 * types from its data (category / indoor_outdoor / air_purifying / pet_friendly).
 * Leaves the filter sidebar with a clean, usable set. Idempotent.
 *
 *   php artisan plantathome:categorize-plants
 */
class CategorizePlantsCommand extends Command
{
    protected $signature = 'plantathome:categorize-plants';

    protected $description = 'Replace granular plant categories with curated type categories and re-assign plants.';

    /** Curated type categories → Unsplash keyword image. */
    private array $types = [
        'Indoor'             => '1545241047-6083a3684587',
        'Outdoor'            => '1416879595882-3373a0480b5b',
        'Flowering'          => '1490750967868-88aa4486c946',
        'Foliage'            => '1597055181300-e3633a917b6f',
        'Air-purifying'      => '1593482892290-f54927ae1bb6',
        'Pet-friendly'       => '1446071103084-c257b5f70672',
        'Succulents & Cacti' => '1459411552884-841db9b3cc2a',
        'Medicinal'          => '1509423350716-97f2360af2e4',
        'Climbers & Vines'   => '1622547748225-3fc4abd2cca0',
        'Herbs'              => '1466692476868-aef1dfb1e735',
    ];

    public function handle(): int
    {
        $type = Type::where('slug', 'plants')->where('language', 'en')->first();
        if (!$type) {
            $this->error('Plants type not found.');
            return self::FAILURE;
        }

        // 1. Ensure the curated type categories exist; collect name → id.
        $ids = [];
        foreach ($this->types as $name => $img) {
            $cat = Category::updateOrCreate(
                ['slug' => Str::slug($name), 'language' => 'en'],
                [
                    'name'    => $name,
                    'type_id' => $type->id,
                    'image'   => ['original' => "https://images.unsplash.com/photo-{$img}?auto=format&fit=crop&w=800&q=80"],
                    'parent'  => null,
                ]
            );
            $ids[$name] = $cat->id;
        }
        $curatedIds = array_values($ids);

        // 2. Load the source data (slug → curated type set).
        $path = __DIR__ . '/../../data/plants.json';
        $plants = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        $bySlug = [];
        foreach ($plants as $p) {
            $slug = $p['slug'] ?? Str::slug($p['name'] ?? '');
            if ($slug) {
                $bySlug[$slug] = $this->typesFor($p, $ids);
            }
        }

        // 3. Re-sync each plant product to its curated categories.
        $synced = 0;
        Product::where('type_id', $type->id)->orderBy('id')->chunkById(300, function ($products) use (&$synced, $bySlug, $ids) {
            foreach ($products as $product) {
                $catIds = $bySlug[$product->slug] ?? [$ids['Indoor']];
                $product->categories()->sync($catIds);
                $synced++;
            }
        });

        // 4. Delete the old granular plant categories (keep only the curated set).
        $deleted = Category::where('type_id', $type->id)->whereNotIn('id', $curatedIds)->delete();

        // 5. Bust the categories cache so the filter refreshes immediately.
        $ver = (int) \Illuminate\Support\Facades\Cache::get('categories:ver', 1);
        \Illuminate\Support\Facades\Cache::forever('categories:ver', $ver + 1);

        $this->info("Curated " . count($curatedIds) . " types; re-categorised {$synced} plants; removed {$deleted} granular categories.");
        return self::SUCCESS;
    }

    /** Derive the curated category ids a plant belongs to. */
    private function typesFor(array $p, array $ids): array
    {
        $out = [];
        $io  = strtolower((string) ($p['indoor_outdoor'] ?? ''));
        $cat = strtolower((string) ($p['category'] ?? ''));

        if (str_contains($io, 'indoor'))  $out[] = $ids['Indoor'];
        if (str_contains($io, 'outdoor')) $out[] = $ids['Outdoor'];

        if (preg_match('/flower|bloom/', $cat))           $out[] = $ids['Flowering'];
        if (preg_match('/vine|climb|creeper/', $cat))     $out[] = $ids['Climbers & Vines'];
        if (preg_match('/medicin/', $cat))                $out[] = $ids['Medicinal'];
        if (preg_match('/herb/', $cat))                   $out[] = $ids['Herbs'];
        if (preg_match('/succulent|cact/', $cat))         $out[] = $ids['Succulents & Cacti'];
        if (preg_match('/foliage|fern|palm|leaf/', $cat)) $out[] = $ids['Foliage'];

        if ($this->truthy($p['air_purifying'] ?? null)) $out[] = $ids['Air-purifying'];
        if ($this->truthy($p['pet_friendly'] ?? null))  $out[] = $ids['Pet-friendly'];

        $out = array_values(array_unique($out));
        return $out ?: [$ids['Indoor']];
    }

    private function truthy($v): bool
    {
        if (is_bool($v)) return $v;
        $s = strtolower((string) $v);
        return in_array($s, ['1', 'true', 'yes', 'y'], true);
    }
}
