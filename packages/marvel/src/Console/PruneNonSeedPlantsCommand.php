<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;

/**
 * Part of the prod "full replace with seed catalog" operation. After the plant
 * bulk seeder upserts the 1530 plants.json plants, this UNPUBLISHES every plant
 * whose slug is NOT in plants.json (the old real/filler plants) by setting
 * status='draft' — so the storefront shows exactly the seed catalog.
 *
 * Unpublish (not delete) keeps order history / FKs intact and is trivially
 * reversible (re-publish, or restore from the catalog backup). Idempotent.
 *
 *   php artisan plantathome:prune-non-seed-plants --dry-run
 *   php artisan plantathome:prune-non-seed-plants
 *   php artisan plantathome:prune-non-seed-plants --republish   (undo)
 */
class PruneNonSeedPlantsCommand extends Command
{
    protected $signature = 'plantathome:prune-non-seed-plants
        {--dry-run : Print how many plants would be unpublished without writing}
        {--republish : Re-publish previously pruned plants (undo)}';

    protected $description = 'Unpublish plant products whose slug is not in plants.json (seed-catalog replace).';

    public function handle(): int
    {
        $type = Type::where('slug', 'plants')->where('language', 'en')->first();
        if (!$type) {
            $this->error('Plants type not found.');
            return self::FAILURE;
        }

        $path = base_path('packages/marvel/data/plants.json');
        if (!file_exists($path)) {
            $this->error("plants.json not found at {$path}");
            return self::FAILURE;
        }
        $plants = json_decode(file_get_contents($path), true) ?: [];
        $seedSlugs = [];
        foreach ($plants as $p) {
            $slug = trim($p['slug'] ?? Str::slug($p['name'] ?? ''));
            if ($slug) {
                $seedSlugs[$slug] = true;
            }
        }
        if (!$seedSlugs) {
            $this->error('No seed slugs parsed from plants.json — aborting (refuse to unpublish everything).');
            return self::FAILURE;
        }
        $this->info('Seed slugs: ' . count($seedSlugs));

        if ($this->option('republish')) {
            // Re-publish plants that are NOT in the seed set but currently draft
            // (best-effort undo; the catalog backup is the authoritative restore).
            $n = Product::where('type_id', $type->id)->where('language', 'en')
                ->where('status', 'draft')
                ->whereNotIn('slug', array_keys($seedSlugs))
                ->update(['status' => 'publish']);
            $this->info("Re-published {$n} non-seed plants.");
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $count = 0;
        Product::where('type_id', $type->id)->where('language', 'en')
            ->where('status', '!=', 'draft')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$count, $seedSlugs, $dry) {
                $toUnpublish = [];
                foreach ($rows as $p) {
                    if (!isset($seedSlugs[(string) $p->slug])) {
                        $toUnpublish[] = $p->id;
                    }
                }
                $count += count($toUnpublish);
                if (!$dry && $toUnpublish) {
                    Product::whereIn('id', $toUnpublish)->update(['status' => 'draft']);
                }
            });

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Non-seed plants unpublished (status=draft): {$count}");
        return self::SUCCESS;
    }
}
