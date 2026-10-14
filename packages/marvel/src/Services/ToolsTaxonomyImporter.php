<?php

namespace Marvel\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;

/**
 * Imports the Gardening Tools taxonomy (packages/marvel/data/tools-taxonomy.json, owner brief
 * 2026-10-14) into the existing categories table of the `tools` vertical.
 *
 * Create-only and idempotent, like every catalogue seeder here: a category is created once and
 * never re-dressed afterwards, so admin edits (names, copy, images, switches, order) survive any
 * number of runs. The only writes to rows it did not create are the ones the data file asks for
 * explicitly, each guarded:
 *   - a legacy root reused as a parent is renamed only while its name is still the legacy name;
 *   - display order is set only while it is 0 (never set);
 *   - placements and product links are added when missing, never removed;
 *   - `retire` switches a category off only when its guard holds.
 * Nothing here creates products, prices, SKUs or images.
 *
 * Every write is recorded in pah_tools_taxonomy_backup, and rollback() replays it newest-first.
 */
class ToolsTaxonomyImporter
{
    public const BACKUP = 'pah_tools_taxonomy_backup';

    private array $report = [];

    private int $typeId = 0;

    private string $runId = '';

    /** slug => category row (English rows, every vertical), kept current as rows are created */
    private array $bySlug = [];

    private array $columns = [];

    public function __construct(private ?string $file = null)
    {
        $this->file ??= dirname(__DIR__, 2) . '/data/tools-taxonomy.json';
    }

    public function run(bool $dryRun = false): array
    {
        $this->report = [
            'created' => 0, 'renamed' => 0, 'ordered' => 0, 'unchanged' => 0,
            'placements' => 0, 'links' => 0, 'retired' => 0, 'aliases' => 0,
            'skipped' => [], 'notes' => [],
        ];
        if (!$this->prepare()) {
            return $this->report;
        }
        $data = $this->data();
        $this->report['aliases'] = count($data['aliases'] ?? []);

        $this->inTransaction($dryRun, function () use ($data) {
            foreach ($data['parents'] as $parent) {
                $this->node($parent, null);
            }
            foreach ($data['keep'] ?? [] as $keep) {
                $this->keep($keep);
            }
            foreach ($data['placements'] ?? [] as $placement) {
                $this->place($placement);
            }
            foreach ($data['product_links'] ?? [] as $productSlug => $categories) {
                $this->link((string) $productSlug, (array) $categories);
            }
            foreach ($data['retire'] ?? [] as $retire) {
                $this->retire($retire);
            }
        });

        if (!$dryRun && $this->wrote()) {
            self::bustCaches();
        }

        return $this->report;
    }

    /**
     * Renames categories to the names in the data file, but only rows no admin has edited
     * (updated_by_user_id is still NULL) — later label changes ship as a data change.
     */
    public function relabel(bool $dryRun = false): array
    {
        $this->report = ['renamed' => 0, 'skipped' => [], 'notes' => []];
        if (!$this->prepare()) {
            return $this->report;
        }
        if (!$this->hasColumn('updated_by_user_id')) {
            $this->report['notes'][] = 'categories.updated_by_user_id is missing: cannot tell admin edits apart, nothing renamed.';

            return $this->report;
        }
        $data = $this->data();
        $this->inTransaction($dryRun, function () use ($data) {
            $walk = function (array $nodes) use (&$walk) {
                foreach ($nodes as $n) {
                    $row = $this->bySlug[$n['slug']] ?? null;
                    if ($row && (int) $row->type_id === $this->typeId && $row->name !== $n['name']) {
                        if ($row->updated_by_user_id === null) {
                            $this->rename((int) $row->id, (string) $row->name, $n['name']);
                        } else {
                            $this->report['skipped'][] = "{$n['slug']}: renamed by an admin, kept as \"{$row->name}\"";
                        }
                    }
                    $walk($n['children'] ?? []);
                }
            };
            $walk($data['parents']);
        });
        if (!$dryRun && $this->report['renamed'] > 0) {
            self::bustCaches();
        }

        return $this->report;
    }

    /**
     * Undoes everything the import recorded, newest first. A category the import created is
     * deleted only while nothing uses it (no products, sub-categories or placements); otherwise
     * it is switched off, never deleted.
     */
    public function rollback(): array
    {
        $report = ['undone' => 0, 'deleted' => 0, 'switched_off' => 0];
        if (!Schema::hasTable(self::BACKUP)) {
            return $report;
        }
        DB::transaction(function () use (&$report) {
            foreach (DB::table(self::BACKUP)->orderByDesc('id')->get() as $b) {
                $id = (int) $b->category_id;
                switch ($b->action) {
                    case 'linked':
                        DB::table('category_product')->where('category_id', $id)->where('product_id', $b->product_id)->delete();
                        break;
                    case 'placed':
                        DB::table('category_placements')->where('category_id', $id)->where('parent_id', (int) $b->old_value)->delete();
                        break;
                    case 'retired':
                        DB::table('categories')->where('id', $id)->update(['is_active' => true]);
                        break;
                    case 'renamed':
                        DB::table('categories')->where('id', $id)->where('name', $b->new_value)->update(['name' => $b->old_value]);
                        break;
                    case 'ordered':
                        DB::table('categories')->where('id', $id)->where('display_order', (int) $b->new_value)->update(['display_order' => 0]);
                        break;
                    case 'created':
                        $inUse = DB::table('category_product')->where('category_id', $id)->exists()
                            || DB::table('categories')->where('parent', $id)->exists()
                            || DB::table('category_placements')->where('category_id', $id)->orWhere('parent_id', $id)->exists();
                        if ($inUse) {
                            DB::table('categories')->where('id', $id)->update(['is_active' => false]);
                            $report['switched_off']++;
                        } else {
                            DB::table('categories')->where('id', $id)->delete();
                            $report['deleted']++;
                        }
                        break;
                }
                $report['undone']++;
            }
            DB::table(self::BACKUP)->delete();
        });
        self::bustCaches();

        return $report;
    }

    /**
     * The Tools attribute templates (data/tools-attributes.json): create-only by code. A
     * definition, a scope or an option is added when missing and never re-dressed, so an admin's
     * renames, units, required flags and retirements survive every run.
     */
    public function importAttributes(bool $dryRun = false): array
    {
        $report = ['definitions' => 0, 'unchanged' => 0, 'scopes' => 0, 'terms' => 0, 'skipped' => [], 'notes' => []];
        $type = DB::table('types')->where('slug', 'tools')->where('language', 'en')->orderBy('id')->first();
        if (!$type) {
            $report['notes'][] = 'No tools vertical yet: nothing to import.';

            return $report;
        }
        if (!Schema::hasColumn('plant_attribute_definitions', 'code') || !Schema::hasTable('plant_attribute_scopes')) {
            throw new \RuntimeException('The attribute tables are not migrated yet: run the migrations first.');
        }
        $data = json_decode((string) file_get_contents(dirname($this->file) . '/tools-attributes.json'), true);
        if (!is_array($data) || !isset($data['definitions'])) {
            throw new \RuntimeException('Unreadable attribute templates (data/tools-attributes.json).');
        }
        $categories = DB::table('categories')->where('language', 'en')->where('type_id', $type->id)->pluck('id', 'slug');

        DB::beginTransaction();
        try {
            $now = now();
            foreach ($data['definitions'] as $i => $d) {
                $row = DB::table('plant_attribute_definitions')->where('code', $d['code'])->first();
                if ($row && $row->type_id !== null && (int) $row->type_id !== (int) $type->id) {
                    $report['skipped'][] = "{$d['code']}: code used by another vertical's attribute — left alone";
                    continue;
                }
                if (!$row) {
                    $slug = $d['code'];
                    for ($n = 2; DB::table('plant_attribute_definitions')->where('slug', $slug)->exists(); $n++) {
                        $slug = "{$d['code']}-{$n}";
                    }
                    $id = DB::table('plant_attribute_definitions')->insertGetId([
                        'name' => $d['name'], 'slug' => $slug, 'code' => $d['code'], 'type' => $d['type'],
                        'unit' => $d['unit'] ?? null, 'is_facet' => (bool) ($d['facet'] ?? false), 'is_active' => true,
                        'sort' => ($i + 1) * 10, 'type_id' => $type->id, 'help_text' => $d['help_text'] ?? null,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $report['definitions']++;
                } else {
                    $id = (int) $row->id;
                    $report['unchanged']++;
                }
                foreach ($d['scopes'] ?? [] as $j => $slug) {
                    $categoryId = $categories[$slug] ?? null;
                    if (!$categoryId) {
                        $report['skipped'][] = "{$d['code']} → {$slug}: category missing";
                        continue;
                    }
                    if (!DB::table('plant_attribute_scopes')->where('definition_id', $id)->where('category_id', $categoryId)->exists()) {
                        DB::table('plant_attribute_scopes')->insert([
                            'definition_id' => $id, 'category_id' => $categoryId, 'is_required' => false, 'sort' => $j,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                        $report['scopes']++;
                    }
                }
                foreach ($d['terms'] ?? [] as $j => $value) {
                    $termSlug = Str::slug($value);
                    if (!DB::table('plant_attribute_terms')->where('definition_id', $id)->where('slug', $termSlug)->exists()) {
                        DB::table('plant_attribute_terms')->insert([
                            'definition_id' => $id, 'value' => $value, 'slug' => $termSlug, 'sort' => ($j + 1) * 10, 'is_active' => true,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                        $report['terms']++;
                    }
                }
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (!$dryRun && ($report['definitions'] || $report['scopes'] || $report['terms'])) {
            Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
        }

        return $report;
    }

    public static function bustCaches(): void
    {
        CategoryTree::bust();
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
    }

    private function prepare(): bool
    {
        $type = DB::table('types')->where('slug', 'tools')->where('language', 'en')->orderBy('id')->first();
        if (!$type) {
            $this->report['notes'][] = 'No tools vertical yet: nothing to import.';

            return false;
        }
        if (!Schema::hasTable('category_placements')) {
            throw new \RuntimeException('category_placements is missing: run the migrations before importing the taxonomy.');
        }
        $this->typeId = (int) $type->id;
        $this->runId = now()->format('YmdHis') . '-' . Str::lower(Str::random(4));
        $this->ensureBackupTable(); // DDL commits implicitly in MySQL: create it outside the transaction

        return true;
    }

    private function inTransaction(bool $dryRun, \Closure $work): void
    {
        DB::beginTransaction();
        try {
            $this->bySlug = DB::table('categories')->where('language', 'en')->orderBy('id')->get()
                ->reduce(fn ($carry, $row) => $carry + [$row->slug => $row], []);
            $work();
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function data(): array
    {
        $data = json_decode((string) file_get_contents($this->file), true);
        if (!is_array($data) || !isset($data['parents'])) {
            throw new \RuntimeException("Unreadable taxonomy data file: {$this->file}");
        }

        return $data;
    }

    private function node(array $n, ?int $parentId): void
    {
        $slug = $n['slug'];
        $row = $this->bySlug[$slug] ?? null;

        if ($row && (int) $row->type_id !== $this->typeId) {
            // /c/{slug} is not scoped by vertical: never move another vertical's category.
            $this->report['skipped'][] = "{$slug}: slug already used by category #{$row->id} in another vertical — left alone, with its sub-tree";

            return;
        }

        if (!$row) {
            $now = now();
            $values = [
                'name' => $n['name'], 'slug' => $slug, 'language' => 'en', 'type_id' => $this->typeId,
                'parent' => $parentId, 'icon' => null, 'details' => null, 'created_at' => $now, 'updated_at' => $now,
            ] + $this->existing([
                'is_active' => true, 'show_on_homepage' => false, 'homepage_sort_order' => 0,
                'display_order' => (int) ($n['order'] ?? 0),
            ]);
            $id = (int) DB::table('categories')->insertGetId($values);
            $this->bySlug[$slug] = (object) ($values + ['id' => $id]);
            $this->backup('created', $id);
            $this->report['created']++;
        } else {
            $id = (int) $row->id;
            $changed = false;
            $legacy = $n['reuse']['legacy_name'] ?? null;
            if ($legacy !== null && $row->name === $legacy && $row->name !== $n['name']) {
                $this->rename($id, (string) $row->name, $n['name']);
                $changed = true;
            }
            if ($this->hasColumn('display_order') && (int) ($row->display_order ?? 0) === 0 && (int) ($n['order'] ?? 0) !== 0) {
                $this->setOrder($id, (int) $n['order']);
                $changed = true;
            }
            if ($parentId !== null && (int) ($row->parent ?? 0) !== $parentId) {
                $this->report['notes'][] = "{$slug}: already exists under another parent — kept where it is (create-only)";
            }
            if (!$changed) {
                $this->report['unchanged']++;
            }
        }

        foreach ($n['children'] ?? [] as $child) {
            $this->node($child, $id);
        }
    }

    private function keep(array $keep): void
    {
        $row = $this->bySlug[$keep['slug']] ?? null;
        if (!$row || (int) $row->type_id !== $this->typeId || !$this->hasColumn('display_order')) {
            return;
        }
        if ((int) ($row->display_order ?? 0) === 0 && (int) ($keep['order'] ?? 0) !== 0) {
            $this->setOrder((int) $row->id, (int) $keep['order']);
        }
    }

    private function place(array $placement): void
    {
        $category = $this->toolsId($placement['slug']);
        $parent = $this->toolsId($placement['also_under']);
        if (!$category || !$parent) {
            $this->report['skipped'][] = "placement {$placement['slug']} → {$placement['also_under']}: category missing";

            return;
        }
        if (DB::table('category_placements')->where('category_id', $category)->where('parent_id', $parent)->exists()) {
            return;
        }
        DB::table('category_placements')->insert([
            'category_id' => $category, 'parent_id' => $parent, 'display_order' => (int) ($placement['order'] ?? 0),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->backup('placed', $category, null, (string) $parent);
        $this->report['placements']++;
    }

    private function link(string $productSlug, array $categories): void
    {
        $productId = DB::table('products')->where('slug', $productSlug)->where('language', 'en')
            ->where('type_id', $this->typeId)->whereNull('deleted_at')->value('id');
        if (!$productId) {
            $this->report['skipped'][] = "product {$productSlug}: not in this catalogue";

            return;
        }
        foreach ($categories as $slug) {
            $categoryId = $this->toolsId($slug);
            if (!$categoryId) {
                $this->report['skipped'][] = "link {$productSlug} → {$slug}: category missing";
                continue;
            }
            if (DB::table('category_product')->where('product_id', $productId)->where('category_id', $categoryId)->exists()) {
                continue;
            }
            DB::table('category_product')->insert(['product_id' => $productId, 'category_id' => $categoryId]);
            $this->backup('linked', $categoryId, (int) $productId);
            $this->report['links']++;
        }
    }

    private function retire(array $retire): void
    {
        $slug = $retire['slug'];
        $row = $this->bySlug[$slug] ?? null;
        if (!$row || (int) $row->type_id !== $this->typeId || !$this->hasColumn('is_active') || !(bool) ($row->is_active ?? true)) {
            return; // not in this environment, or already off
        }
        $id = (int) $row->id;
        $subtree = DB::table('categories')->where('parent', $id)->count()
            + DB::table('category_placements')->where('parent_id', $id)->count();

        if (($retire['guard'] ?? 'empty') === 'after-links') {
            // Every product a shopper can see here must still be findable in another live category.
            $stranded = $this->listable(DB::table('category_product as cp')->join('products as p', 'p.id', '=', 'cp.product_id'))
                ->where('cp.category_id', $id)
                ->whereNotExists(function ($q) use ($id) {
                    $q->selectRaw('1')->from('category_product as o')->join('categories as c', 'c.id', '=', 'o.category_id')
                        ->whereColumn('o.product_id', 'cp.product_id')->where('o.category_id', '!=', $id)
                        ->where('c.type_id', $this->typeId)->where('c.is_active', true);
                })
                ->count();
            if ($stranded || $subtree) {
                $this->report['skipped'][] = "retire {$slug}: {$stranded} listed products have no other category, {$subtree} sub-categories — kept";

                return;
            }
        } else {
            // "Empty" = nothing a shopper can see: no LISTED product in it (unlisted or draft
            // products keep their links and the category its URL; switching it off hides only
            // the category from navigation). Production's stale Tools roots hold 6 such links.
            $products = $this->listable(DB::table('category_product as cp')->join('products as p', 'p.id', '=', 'cp.product_id'))
                ->where('cp.category_id', $id)
                ->count();
            $onHome = (bool) ($row->show_on_homepage ?? false);
            if ($products || $subtree || $onHome) {
                $this->report['skipped'][] = "retire {$slug}: {$products} listed products, {$subtree} sub-categories" . ($onHome ? ', on the homepage' : '') . ' — kept';

                return;
            }
        }
        DB::table('categories')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
        $this->backup('retired', $id);
        $this->report['retired']++;
    }

    private function listable($query)
    {
        $query->where('p.status', 'publish')->where('p.visibility', 'visibility_public')->whereNull('p.deleted_at');
        if (Schema::hasColumn('products', 'is_available_product')) {
            $query->where('p.is_available_product', true)->where('p.listing_enabled', true);
        }

        return $query;
    }

    private function rename(int $id, string $old, string $new): void
    {
        // Through the model so the translation overlay marks the old name's translations outdated.
        $category = Category::query()->find($id);
        if (!$category) {
            return;
        }
        $category->name = $new;
        $category->save();
        $this->backup('renamed', $id, null, $old, $new);
        $this->report['renamed']++;
    }

    private function setOrder(int $id, int $order): void
    {
        DB::table('categories')->where('id', $id)->update(['display_order' => $order]);
        $this->backup('ordered', $id, null, '0', (string) $order);
        $this->report['ordered']++;
    }

    private function toolsId(string $slug): ?int
    {
        $row = $this->bySlug[$slug] ?? null;

        return $row && (int) $row->type_id === $this->typeId ? (int) $row->id : null;
    }

    private function wrote(): bool
    {
        foreach (['created', 'renamed', 'ordered', 'placements', 'links', 'retired'] as $key) {
            if (($this->report[$key] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function ensureBackupTable(): void
    {
        if (Schema::hasTable(self::BACKUP)) {
            return;
        }
        Schema::create(self::BACKUP, function (Blueprint $t) {
            $t->id();
            $t->string('run_id', 32);
            $t->string('action', 16); // created | renamed | ordered | placed | linked | retired
            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('old_value')->nullable();
            $t->string('new_value')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    private function backup(string $action, int $categoryId, ?int $productId = null, ?string $old = null, ?string $new = null): void
    {
        DB::table(self::BACKUP)->insert([
            'run_id' => $this->runId, 'action' => $action, 'category_id' => $categoryId, 'product_id' => $productId,
            'old_value' => $old, 'new_value' => $new, 'created_at' => now(),
        ]);
    }

    /** Only the columns this database has (older schemas lack the later category columns). */
    private function existing(array $values): array
    {
        return array_filter($values, fn ($col) => $this->hasColumn($col), ARRAY_FILTER_USE_KEY);
    }

    private function hasColumn(string $column): bool
    {
        return $this->columns[$column] ??= Schema::hasColumn('categories', $column);
    }
}
