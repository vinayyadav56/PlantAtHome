<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The category hierarchy in one place: primary parents (`categories.parent`) plus
 * placements (`category_placements`: a category also listed under another parent).
 *
 * Built from two flat queries and cached per `categories:ver`, so every caller (product
 * filters, the tree endpoint, parent validation) reads the same snapshot, and any category
 * write, which bumps the version, invalidates all of them at once.
 *
 * A primary parent defines a category's ancestry (URL, breadcrumb). A placement only adds the
 * category to another parent's children and product roll-up. Every walk keeps a visited set,
 * so a cycle in bad data can never loop.
 */
class CategoryTree
{
    private ?int $ver = null;

    private array $snap = [];

    private array $columns = [];

    public static function version(): int
    {
        return (int) Cache::get('categories:ver', 1);
    }

    /** Bump the version every category cache keys on (the index, the tree, this snapshot). */
    public static function bust(): void
    {
        Cache::forever('categories:ver', self::version() + 1);
    }

    public function node(int $id): ?array
    {
        return $this->snap()['nodes'][$id] ?? null;
    }

    public function idForSlug(string $slug): ?int
    {
        return $this->snap()['bySlug'][$slug] ?? null;
    }

    /** Ordered children of a category: [['id' => int, 'placement' => bool], ...]. */
    public function children(int $id): array
    {
        return array_map(
            fn ($c) => ['id' => $c[0], 'placement' => $c[2]],
            $this->snap()['children'][$id] ?? []
        );
    }

    /** The given ids plus every descendant, through primary parents and placements. */
    public function descendantIds(array $ids): array
    {
        $children = $this->snap()['children'];
        $seen = [];
        $queue = array_map('intval', $ids);
        while ($queue) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($children[$id] ?? [] as $c) {
                $queue[] = $c[0];
            }
        }

        return array_keys($seen);
    }

    /** Category slugs plus every descendant's slug. Unknown slugs pass through, so they still match nothing. */
    public function expandSlugs(array $slugs): array
    {
        $snap = $this->snap();
        $out = [];
        $ids = [];
        foreach ($slugs as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            $out[$slug] = true;
            if (isset($snap['bySlug'][$slug])) {
                $ids[] = $snap['bySlug'][$slug];
            }
        }
        foreach ($this->descendantIds($ids) as $id) {
            $out[$snap['nodes'][$id]['slug']] = true;
        }

        return array_keys($out);
    }

    /** Ids for a mixed list of category ids and slugs, plus every descendant. */
    public function expandRefs(array $refs): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            $id = ctype_digit($ref) ? (int) $ref : $this->idForSlug($ref);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $this->descendantIds($ids);
    }

    /** The primary-parent chain of a category, root first, the category itself excluded. */
    public function ancestors(int $id): array
    {
        $nodes = $this->snap()['nodes'];
        $chain = [];
        $seen = [$id => true];
        $parent = $nodes[$id]['parent'] ?? null;
        while ($parent !== null && isset($nodes[$parent]) && !isset($seen[$parent])) {
            $seen[$parent] = true;
            array_unshift($chain, ['id' => $parent, 'slug' => $nodes[$parent]['slug'], 'name' => $nodes[$parent]['name']]);
            $parent = $nodes[$parent]['parent'];
        }

        return $chain;
    }

    /** Whether making $newParentId the parent (or a placement parent) of $categoryId creates a cycle. */
    public function wouldCycle(int $categoryId, int $newParentId): bool
    {
        return $newParentId === $categoryId
            || in_array($newParentId, $this->descendantIds([$categoryId]), true);
    }

    /**
     * The nested tree of one vertical (every vertical when $typeId is null), in display order.
     * A placement entry carries `placement: true` and no children of its own (the canonical
     * entry has them). Inactive categories and their sub-trees are left out unless asked for.
     */
    public function tree(?int $typeId, bool $withInactive, array $counts = []): array
    {
        $snap = $this->snap();
        $roots = [];
        foreach ($snap['nodes'] as $id => $n) {
            $isRoot = $n['parent'] === null || !isset($snap['nodes'][$n['parent']]);
            if ($isRoot && ($typeId === null || $n['type_id'] === $typeId)) {
                $roots[] = [$id, $n['display_order'], false];
            }
        }
        $this->sortEntries($roots, $snap['nodes']);

        $build = function (array $entry, int $depth, array $path) use (&$build, $snap, $withInactive, $counts): ?array {
            [$id, $order, $placement] = $entry;
            $n = $snap['nodes'][$id];
            if (!$withInactive && !$n['is_active']) {
                return null;
            }
            $node = [
                'id'             => $id,
                'slug'           => $n['slug'],
                'name'           => $n['name'],
                'image'          => $n['image'],
                'icon'           => $n['icon'],
                'is_active'      => $n['is_active'],
                'noindex'        => $n['noindex'],
                'display_order'  => $placement ? $order : $n['display_order'],
                'depth'          => $depth,
                'parent_id'      => $n['parent'],
                'type_id'        => $n['type_id'],
                'products_count' => $counts[$id] ?? 0,
                'children'       => [],
            ];
            if ($placement) {
                $node['placement'] = true;
                $node['canonical_parent_id'] = $n['parent'];

                return $node;
            }
            if (isset($path[$id])) {
                return $node; // cycle guard: bad data never loops
            }
            $path[$id] = true;
            foreach ($snap['children'][$id] ?? [] as $child) {
                $built = $build($child, $depth + 1, $path);
                if ($built !== null) {
                    $node['children'][] = $built;
                }
            }

            return $node;
        };

        $out = [];
        foreach ($roots as $root) {
            $built = $build($root, 1, []);
            if ($built !== null) {
                $out[] = $built;
            }
        }

        return $out;
    }

    /**
     * Listable products per category (the storefront's own gates: published, public, in the
     * catalogue and listed, priced), rolled up through descendants and placements as DISTINCT
     * products, so a product in a leaf counts once on every ancestor. One query per vertical,
     * cached on both the category and the product versions.
     */
    public function listableCounts(?int $typeId): array
    {
        $key = 'categories:counts:v' . self::version() . ':p' . (int) Cache::get('products:ver', 1) . ':' . ($typeId ?? 'all');

        return Cache::remember($key, 600, function () use ($typeId) {
            $query = DB::table('category_product as cp')
                ->join('products as p', 'p.id', '=', 'cp.product_id')
                ->join('categories as c', 'c.id', '=', 'cp.category_id')
                ->where('p.language', DEFAULT_LANGUAGE)
                ->where('p.status', 'publish')
                ->where('p.visibility', 'visibility_public')
                ->whereNull('p.deleted_at')
                ->where(fn ($w) => $w->where('p.price', '>', 0)->orWhere('p.sale_price', '>', 0)->orWhere('p.max_price', '>', 0));
            if (Schema::hasColumn('products', 'is_available_product')) {
                $query->where('p.is_available_product', true)->where('p.listing_enabled', true);
            }
            if ($typeId !== null) {
                $query->where('c.type_id', $typeId);
            }
            $direct = [];
            foreach ($query->distinct()->get(['cp.category_id', 'cp.product_id']) as $row) {
                $direct[(int) $row->category_id][(int) $row->product_id] = true;
            }

            // ponytail: one walk per category (fine for a few thousand); memoise per node if it ever isn't.
            $counts = [];
            foreach ($this->snap()['nodes'] as $id => $n) {
                if ($typeId !== null && $n['type_id'] !== $typeId) {
                    continue;
                }
                $products = [];
                foreach ($this->descendantIds([$id]) as $d) {
                    $products += $direct[$d] ?? [];
                }
                if ($products) {
                    $counts[$id] = count($products);
                }
            }

            return $counts;
        });
    }

    private function snap(): array
    {
        $ver = self::version();
        if ($this->ver !== $ver) {
            $this->snap = Cache::remember("categories:tree:v{$ver}", 600, fn () => $this->build());
            $this->ver = $ver;
        }

        return $this->snap;
    }

    private function build(): array
    {
        // Only id/name/slug are certain: older schemas (and lean test fixtures) lack the rest.
        $optional = array_values(array_filter(
            ['parent', 'type_id', 'image', 'icon', 'is_active', 'noindex', 'display_order', 'deleted_at'],
            fn ($col) => $this->hasColumn('categories', $col)
        ));
        $query = DB::table('categories');
        if ($this->hasColumn('categories', 'language')) {
            $query->where('language', DEFAULT_LANGUAGE);
        }
        $rows = $query->get(array_merge(['id', 'name', 'slug'], $optional));

        $nodes = [];
        $bySlug = [];
        foreach ($rows as $r) {
            if (!empty($r->deleted_at)) {
                continue; // raw soft-deleted rows (data migrations) are not part of the tree
            }
            $nodes[(int) $r->id] = [
                'id'            => (int) $r->id,
                'slug'          => (string) $r->slug,
                'name'          => (string) $r->name,
                'parent'        => !empty($r->parent) ? (int) $r->parent : null,
                'type_id'       => (int) ($r->type_id ?? 0),
                'is_active'     => (bool) ($r->is_active ?? true),
                'noindex'       => (bool) ($r->noindex ?? false),
                'display_order' => (int) ($r->display_order ?? 0),
                'image'         => is_string($r->image ?? null) ? json_decode($r->image, true) : ($r->image ?? null),
                'icon'          => $r->icon ?? null,
            ];
            $bySlug[(string) $r->slug] = (int) $r->id;
        }

        // parent id => [[child id, display order, is placement], ...]
        $children = [];
        foreach ($nodes as $id => $n) {
            if ($n['parent'] !== null && isset($nodes[$n['parent']])) {
                $children[$n['parent']][] = [$id, $n['display_order'], false];
            }
        }
        if ($this->hasTable('category_placements')) {
            foreach (DB::table('category_placements')->get(['category_id', 'parent_id', 'display_order']) as $p) {
                $child = (int) $p->category_id;
                $parent = (int) $p->parent_id;
                if (isset($nodes[$child], $nodes[$parent]) && $nodes[$child]['parent'] !== $parent) {
                    $children[$parent][] = [$child, (int) $p->display_order, true];
                }
            }
        }
        foreach ($children as &$list) {
            $this->sortEntries($list, $nodes);
        }
        unset($list);

        return ['nodes' => $nodes, 'bySlug' => $bySlug, 'children' => $children];
    }

    private function sortEntries(array &$entries, array $nodes): void
    {
        usort($entries, fn ($a, $b) => [$a[1], $nodes[$a[0]]['name']] <=> [$b[1], $nodes[$b[0]]['name']]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->columns["{$table}.{$column}"] ??= Schema::hasColumn($table, $column);
    }

    private function hasTable(string $table): bool
    {
        return $this->columns[$table] ??= Schema::hasTable($table);
    }
}
