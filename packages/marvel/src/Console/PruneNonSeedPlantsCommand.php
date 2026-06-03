<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;

/**
 * Part of the prod "full replace with seed catalog" operation. After the plant
 * bulk seeder upserts the 1530 plants.json plants, this **soft-deletes** every
 * plant whose slug is NOT in plants.json (the old real/filler plants) so the
 * storefront shows exactly the seed catalog.
 *
 * Soft-delete (not status=draft, which the products API does NOT filter out;
 * not hard-delete, which would break order FKs) → the rows stay in the DB with
 * deleted_at set, are excluded from every Eloquent query (API + storefront),
 * and are restorable with --restore (or from the catalog backup). Idempotent.
 *
 *   php artisan plantathome:prune-non-seed-plants --dry-run
 *   php artisan plantathome:prune-non-seed-plants
 *   php artisan plantathome:prune-non-seed-plants --restore   (undo)
 */
class PruneNonSeedPlantsCommand extends Command
{
    protected $signature = 'plantathome:prune-non-seed-plants
        {--dry-run : Print how many plants would be soft-deleted without writing}
        {--restore : Restore previously pruned (soft-deleted) non-seed plants (undo)}';

    protected $description = 'Soft-delete plant products whose slug is not in plants.json (seed-catalog replace).';

    public function handle(): int
    {
        $type = Type::where('slug', 'plants')->where('language', 'en')->first();
        if (!$type) {
            $this->error('Plants type not found.');
            return self::FAILURE;
        }

        $seedSlugs = $this->seedSlugs();
        if (!$seedSlugs) {
            $this->error('No seed slugs parsed from plants.json — aborting (refuse to prune everything).');
            return self::FAILURE;
        }
        $this->info('Seed slugs: ' . count($seedSlugs));

        if ($this->option('restore')) {
            $ids = Product::onlyTrashed()->where('type_id', $type->id)->where('language', 'en')
                ->pluck('slug', 'id')->reject(fn ($slug) => isset($seedSlugs[(string) $slug]))->keys()->all();
            $n = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $n += Product::onlyTrashed()->whereIn('id', $chunk)->restore();
            }
            $this->info("Restored {$n} previously pruned plants.");
            return self::SUCCESS;
        }

        // All live (non-trashed) plants whose slug is NOT in the seed set.
        $ids = Product::where('type_id', $type->id)->where('language', 'en')
            ->pluck('slug', 'id')->reject(fn ($slug) => isset($seedSlugs[(string) $slug]))->keys()->all();

        $dry = (bool) $this->option('dry-run');
        if (!$dry) {
            foreach (array_chunk($ids, 500) as $chunk) {
                Product::whereIn('id', $chunk)->delete(); // soft delete (deleted_at)
            }
            \Illuminate\Support\Facades\Cache::forever('products:ver', (int) \Illuminate\Support\Facades\Cache::get('products:ver', 1) + 1);
        }
        $this->info(($dry ? '[DRY-RUN] ' : '') . 'Non-seed plants soft-deleted: ' . count($ids));
        return self::SUCCESS;
    }

    private function seedSlugs(): array
    {
        $path = base_path('packages/marvel/data/plants.json');
        if (!file_exists($path)) {
            return [];
        }
        $plants = json_decode(file_get_contents($path), true) ?: [];
        $slugs = [];
        foreach ($plants as $p) {
            $slug = trim($p['slug'] ?? Str::slug($p['name'] ?? ''));
            if ($slug) {
                $slugs[$slug] = true;
            }
        }
        return $slugs;
    }
}
