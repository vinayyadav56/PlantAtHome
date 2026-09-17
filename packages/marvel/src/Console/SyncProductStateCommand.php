<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;

/**
 * Bring this database's product STATE into line with staging: availability,
 * listing, stock, pricing and the Small/Medium/Large variant ladder — driven
 * by a manifest exported from the staging DB
 * (packages/marvel/database/data/product-state-staging.json, 866 listed plants).
 *
 * Why a manifest and not a rule: staging's listed plants carry hand-set
 * per-size prices and stock; production has the same slugs but unavailable,
 * unlisted, ₹0 and `simple`. Only the data itself reproduces that state.
 *
 * Products are matched by slug. Variants are matched by title (Small/Medium/
 * Large) — existing rows are updated in place so their ids stay stable,
 * missing ones are created. Products/variants absent from the manifest are
 * never touched. Slugs the manifest has but this DB lacks are reported.
 *
 * Safety: every touched product's original columns and every pre-existing
 * variant row are snapshotted ONCE (pah_product_state_backup /
 * pah_variation_state_backup); variants this command creates are tagged so
 * --rollback deletes exactly those and restores everything else. --dry-run
 * writes nothing (not even the backup tables); --limit stages the rollout.
 * Idempotent: a second run reports every product as unchanged.
 *
 *   php artisan plantathome:sync-product-state --dry-run
 *   php artisan plantathome:sync-product-state --limit=10
 *   php artisan plantathome:sync-product-state
 *   php artisan plantathome:sync-product-state --rollback
 */
class SyncProductStateCommand extends Command
{
    protected $signature = 'plantathome:sync-product-state
        {--manifest=packages/marvel/database/data/product-state-staging.json : Manifest path, relative to base_path()}
        {--dry-run : Report what would change and write nothing}
        {--limit=0 : Stop after N changed products (staged rollout)}
        {--rollback : Restore every product this command touched from its backup}';

    protected $description = 'Sync product state (availability, listing, stock, prices, size variants) from the staging manifest';

    private const PRODUCT_COLS = [
        'status', 'is_available_product', 'listing_enabled', 'in_stock', 'quantity',
        'price', 'sale_price', 'min_price', 'max_price', 'product_type', 'sku', 'unit',
        'visibility', 'track_stock',
    ];

    private const VARIANT_COLS = ['price', 'sale_price', 'quantity', 'is_disable', 'sku', 'options'];

    private const MONEY_COLS = ['price', 'sale_price', 'min_price', 'max_price'];
    private const BOOL_COLS  = ['is_available_product', 'listing_enabled', 'in_stock', 'is_disable', 'track_stock'];

    /** shop_id => [size => attribute_value id] */
    private array $sizeValueIds = [];

    /** products.sku is UNIQUE and each environment generated its own PLT-IND series in
     *  its own order — when another product here already owns the manifest sku, ours is kept. */
    private int $skuConflicts = 0;

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $path = base_path($this->option('manifest'));
        if (! is_file($path)) {
            $this->error("Manifest not found: {$path}");
            return self::FAILURE;
        }
        $rows = json_decode((string) file_get_contents($path), true)['products'] ?? null;
        if (! is_array($rows)) {
            $this->error('Manifest has no products[]');
            return self::FAILURE;
        }

        $dry   = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // Backup tables are DDL: on MySQL a CREATE TABLE implicitly COMMITs, so it
        // must never run inside the per-product transaction below (the first
        // rollout attempt died at commit with "no active transaction").
        if (! $dry) {
            $this->ensureBackupTables();
        }

        $stats = ['matched' => 0, 'unchanged' => 0, 'changed' => 0, 'converted' => 0,
                  'variants_created' => 0, 'variants_updated' => 0, 'missing' => []];
        $samples = [];

        foreach ($rows as $want) {
            $slug = (string) ($want['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $p = Product::where('slug', $slug)->where('language', 'en')->first();
            if (! $p) {
                $stats['missing'][] = $slug;
                continue;
            }
            $stats['matched']++;

            $plan = $this->plan($p, $want);
            if (! $plan['product'] && ! $plan['create'] && ! $plan['update']) {
                $stats['unchanged']++;
                continue;
            }
            if ($limit > 0 && $stats['changed'] >= $limit) {
                break;
            }

            $stats['changed']++;
            if ($p->product_type !== 'variable' && ($want['product_type'] ?? 'variable') === 'variable') {
                $stats['converted']++;
            }
            $stats['variants_created'] += count($plan['create']);
            $stats['variants_updated'] += count($plan['update']);
            if (count($samples) < 8) {
                $samples[] = sprintf(
                    '%-42s %-8s → %-8s  %s → %s   variants +%d ~%d',
                    Str::limit($slug, 41), $p->product_type, $want['product_type'] ?? $p->product_type,
                    $this->range($p->min_price, $p->max_price), $this->range($want['min_price'] ?? null, $want['max_price'] ?? null),
                    count($plan['create']), count($plan['update'])
                );
            }

            if (! $dry) {
                DB::transaction(fn () => $this->apply($p, $want, $plan));
            }
        }

        $this->info(sprintf(
            '%smatched %d · changed %d (simple→variable %d) · unchanged %d · variants +%d ~%d · missing on this DB %d · sku kept (unique conflict) %d',
            $dry ? '[DRY-RUN] ' : '', $stats['matched'], $stats['changed'], $stats['converted'],
            $stats['unchanged'], $stats['variants_created'], $stats['variants_updated'], count($stats['missing']), $this->skuConflicts
        ));
        foreach ($samples as $s) {
            $this->line('   ' . $s);
        }
        if ($stats['missing']) {
            $this->warn('missing: ' . implode(', ', array_slice($stats['missing'], 0, 20)) . (count($stats['missing']) > 20 ? ' …' : ''));
        }

        if ($dry) {
            $this->warn('Dry-run only — nothing written. Re-run without --dry-run (or via the workflow mode) to apply.');
        } else {
            $this->bustProductCache();
            $this->info('Done. Product cache busted.');
        }
        $listed = Product::where('is_available_product', true)->where('listing_enabled', true)->count();
        $this->info("now listed: {$listed}");

        return self::SUCCESS;
    }

    /** Diff one product (and its variants, by title) against the manifest row. */
    private function plan(Product $p, array $want): array
    {
        $product = [];
        foreach (self::PRODUCT_COLS as $col) {
            if (! array_key_exists($col, $want) || $this->same($p->{$col}, $want[$col], $col)) {
                continue;
            }
            if ($col === 'sku' && $want[$col] !== null && $want[$col] !== ''
                && DB::table('products')->where('sku', $want[$col])->where('id', '!=', $p->id)->exists()) {
                $this->skuConflicts++;
                continue;
            }
            $product[$col] = $want[$col];
        }

        $existing = $p->variation_options()->get()->keyBy(fn ($v) => (string) $v->title);
        $create = [];
        $update = [];
        foreach ($want['variants'] ?? [] as $v) {
            $title = (string) ($v['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $cur = $existing->get($title);
            if (! $cur) {
                $create[] = $v;
                continue;
            }
            $diff = [];
            foreach (self::VARIANT_COLS as $col) {
                if (array_key_exists($col, $v) && ! $this->same($cur->{$col}, $v[$col], $col)) {
                    $diff[$col] = $col === 'options' ? $this->normOptions($v[$col]) : $v[$col];
                }
            }
            if ($diff) {
                $update[$cur->id] = $diff;
            }
        }

        return ['product' => $product, 'create' => $create, 'update' => $update];
    }

    /** Snapshot once, then write the planned product/variant changes (DML only — runs in a transaction). */
    private function apply(Product $p, array $want, array $plan): void
    {
        if (! DB::table('pah_product_state_backup')->where('product_id', $p->id)->exists()) {
            $row = ['product_id' => $p->id, 'backed_up_at' => now()];
            foreach (self::PRODUCT_COLS as $col) {
                $row[$col] = $p->{$col};
            }
            DB::table('pah_product_state_backup')->insert($row);
        }
        foreach ($p->variation_options()->get() as $v) {
            if (! DB::table('pah_variation_state_backup')->where('variation_id', $v->id)->exists()) {
                DB::table('pah_variation_state_backup')->insert([
                    'variation_id' => $v->id, 'product_id' => $p->id, 'existed' => 1,
                    'title' => $v->title, 'price' => $v->price, 'sale_price' => $v->sale_price,
                    'quantity' => $v->quantity, 'is_disable' => (int) $v->is_disable, 'sku' => $v->sku,
                    'options' => json_encode($this->normOptions($v->options)), 'backed_up_at' => now(),
                ]);
            }
        }

        foreach ($plan['update'] as $id => $diff) {
            $p->variation_options()->where('id', $id)->first()?->forceFill($diff)->save();
        }
        foreach ($plan['create'] as $v) {
            $new = $p->variation_options()->create([
                'title'      => $v['title'],
                'price'      => $v['price'],
                'sale_price' => $v['sale_price'] ?? null,
                'quantity'   => (int) ($v['quantity'] ?? 0),
                'sku'        => ($v['sku'] ?? null) ?: ($p->slug . '-' . Str::slug($v['title'])),
                'is_disable' => (bool) ($v['is_disable'] ?? false),
                'language'   => 'en',
                'options'    => $this->normOptions($v['options'] ?? []),
            ]);
            DB::table('pah_variation_state_backup')->insert([
                'variation_id' => $new->id, 'product_id' => $p->id, 'existed' => 0,
                'title' => $v['title'], 'backed_up_at' => now(),
            ]);
        }

        // Attach the Size attribute values the variants use.
        $sizes = [];
        foreach ($want['variants'] ?? [] as $v) {
            foreach ($this->normOptions($v['options'] ?? []) as $o) {
                if (($o['name'] ?? '') === 'Size' && ! empty($o['value'])) {
                    $sizes[(string) $o['value']] = true;
                }
            }
        }
        if ($sizes) {
            $ids = array_values($this->sizeValueIds(array_keys($sizes)));
            // Detach first: syncWithoutDetaching alone used to pile the new ids on
            // top of whatever a previous run had attached, which is how products
            // ended up carrying six pivot rows for three sizes (doubled chips).
            $stale = array_diff(allSizeValueIds(), $ids);
            if ($stale) {
                $p->variations()->detach($stale);
            }
            $p->variations()->syncWithoutDetaching($ids);
        }

        if ($plan['product']) {
            $p->forceFill($plan['product'])->save();
        }
    }

    private function rollback(): int
    {
        if (! Schema::hasTable('pah_product_state_backup')) {
            $this->warn('No backup rows — nothing to roll back.');
            return self::SUCCESS;
        }
        $restored = 0;
        foreach (DB::table('pah_product_state_backup')->get() as $b) {
            $p = Product::find($b->product_id);
            if (! $p) {
                continue;
            }
            DB::transaction(function () use ($p, $b, &$restored) {
                foreach (DB::table('pah_variation_state_backup')->where('product_id', $p->id)->get() as $v) {
                    if (! $v->existed) {
                        $p->variation_options()->where('id', $v->variation_id)->delete();
                        continue;
                    }
                    $p->variation_options()->where('id', $v->variation_id)->first()?->forceFill([
                        'price' => $v->price, 'sale_price' => $v->sale_price, 'quantity' => $v->quantity,
                        'is_disable' => (bool) $v->is_disable, 'sku' => $v->sku,
                        'options' => $this->normOptions($v->options),
                    ])->save();
                }
                $cols = [];
                foreach (self::PRODUCT_COLS as $col) {
                    $cols[$col] = $b->{$col};
                }
                $p->forceFill($cols)->save();
                $restored++;
            });
        }
        $this->bustProductCache();
        $this->info("Rolled back {$restored} products to their pre-sync state.");
        return self::SUCCESS;
    }

    private function ensureBackupTables(): void
    {
        if (! Schema::hasTable('pah_product_state_backup')) {
            Schema::create('pah_product_state_backup', function (Blueprint $t) {
                $t->unsignedBigInteger('product_id')->primary();
                $t->string('product_type', 32)->nullable();
                $t->string('status', 32)->nullable();
                $t->boolean('is_available_product')->nullable();
                $t->boolean('listing_enabled')->nullable();
                $t->boolean('in_stock')->nullable();
                $t->integer('quantity')->nullable();
                $t->decimal('price', 12, 2)->nullable();
                $t->decimal('sale_price', 12, 2)->nullable();
                $t->decimal('min_price', 12, 2)->nullable();
                $t->decimal('max_price', 12, 2)->nullable();
                $t->string('sku')->nullable();
                $t->string('unit')->nullable();
                $t->string('visibility', 32)->nullable();
                $t->boolean('track_stock')->nullable();
                $t->dateTime('backed_up_at')->nullable();
            });
        }
        if (! Schema::hasTable('pah_variation_state_backup')) {
            Schema::create('pah_variation_state_backup', function (Blueprint $t) {
                $t->unsignedBigInteger('variation_id')->primary();
                $t->unsignedBigInteger('product_id')->index();
                $t->boolean('existed')->default(true);
                $t->string('title')->nullable();
                $t->decimal('price', 12, 2)->nullable();
                $t->decimal('sale_price', 12, 2)->nullable();
                $t->integer('quantity')->nullable();
                $t->boolean('is_disable')->nullable();
                $t->string('sku')->nullable();
                $t->text('options')->nullable();
                $t->dateTime('backed_up_at')->nullable();
            });
        }
    }

    /**
     * Size value ids, memoised for the run. Resolution itself lives in the
     * shared helper — this used to key firstOrCreate on the product's shop_id,
     * so a product from a different shop minted a whole second "Size" attribute.
     *
     * @param  array<int, string> $sizes
     * @return array<string, int>
     */
    private function sizeValueIds(array $sizes): array
    {
        $missing = array_values(array_diff($sizes, array_keys($this->sizeValueIds)));
        if ($missing) {
            $this->sizeValueIds += sizeValueIds($missing);
        }

        return array_intersect_key($this->sizeValueIds, array_flip($sizes));
    }

    private function same($a, $b, string $col): bool
    {
        if ($col === 'options') {
            return json_encode($this->normOptions($a)) === json_encode($this->normOptions($b));
        }
        if (in_array($col, self::MONEY_COLS, true)) {
            $aNull = $a === null || $a === '';
            $bNull = $b === null || $b === '';
            if ($aNull || $bNull) {
                return $aNull && $bNull;
            }
            return abs((float) $a - (float) $b) < 0.005;
        }
        if (in_array($col, self::BOOL_COLS, true)) {
            return (bool) $a === (bool) $b;
        }
        if ($col === 'quantity') {
            return (int) $a === (int) $b;
        }
        return (string) ($a ?? '') === (string) ($b ?? '');
    }

    private function normOptions($o): array
    {
        if (is_string($o)) {
            $o = json_decode($o, true);
        }
        return is_array($o) ? array_values($o) : [];
    }

    private function range($min, $max): string
    {
        return '₹' . number_format((float) $min) . '-' . number_format((float) $max);
    }

    private function bustProductCache(): void
    {
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
    }
}
