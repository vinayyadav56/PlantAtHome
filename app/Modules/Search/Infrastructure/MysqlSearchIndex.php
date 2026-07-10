<?php

namespace App\Modules\Search\Infrastructure;

use App\Modules\Search\Infrastructure\Models\SearchProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The always-available search backend, querying the search_products projection.
 * Uses a MySQL FULLTEXT index for relevance + typo tolerance where available,
 * and falls back to LIKE (e.g. on sqlite in tests) — a genuinely degraded but
 * functional search, exactly as Section 12's fallback intends.
 */
class MysqlSearchIndex implements SearchIndex
{
    public function available(): bool
    {
        return true;
    }

    public function upsert(array $doc): void
    {
        SearchProduct::updateOrCreate(
            ['product_uuid' => $doc['product_uuid']],
            [
                'name'          => $doc['name'],
                'slug'          => $doc['slug'] ?? '',
                'category_uuid' => $doc['category_uuid'] ?? null,
                'category_name' => $doc['category_name'] ?? null,
                'attributes'    => $doc['attributes'] ?? [],
                'keywords'      => $doc['keywords'] ?? $doc['name'],
                'price_min'     => $doc['price_min'] ?? null,
                'price_max'     => $doc['price_max'] ?? null,
                'status'        => $doc['status'] ?? 'published',
            ],
        );
    }

    public function remove(string $productUuid): void
    {
        SearchProduct::where('product_uuid', $productUuid)->delete();
    }

    public function search(string $query, array $filters, int $limit): array
    {
        $status = $filters['status'] ?? 'published';

        $applyText = function (Builder $q) use ($query) {
            if ($query === '') {
                return;
            }
            $like = '%'.$query.'%';
            if (DB::connection()->getDriverName() === 'mysql') {
                $q->where(function (Builder $w) use ($query, $like) {
                    $w->whereRaw('MATCH(name, keywords) AGAINST (? IN NATURAL LANGUAGE MODE)', [$query])
                        ->orWhere('name', 'like', $like)
                        ->orWhere('keywords', 'like', $like);
                });
            } else {
                $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('keywords', 'like', $like));
            }
        };

        $matched = SearchProduct::query()->when($status !== 'all', fn ($q) => $q->where('status', $status))->tap($applyText);

        // Facets computed over the text-matched set, BEFORE the category filter.
        $facets = ['category' => (clone $matched)
            ->whereNotNull('category_uuid')
            ->select('category_uuid', 'category_name', DB::raw('count(*) as count'))
            ->groupBy('category_uuid', 'category_name')
            ->get()
            ->map(fn ($r) => ['uuid' => $r->category_uuid, 'name' => $r->category_name, 'count' => (int) $r->count])
            ->all()];

        $results = clone $matched;
        if (! empty($filters['category'])) {
            $results->where('category_uuid', $filters['category']);
        }
        $total = (clone $results)->count();

        if ($query !== '' && DB::connection()->getDriverName() === 'mysql') {
            $results->orderByRaw('MATCH(name, keywords) AGAINST (?) DESC', [$query]);
        } else {
            $results->orderBy('name');
        }

        return [
            'hits'   => $results->limit($limit)->get()->map(fn (SearchProduct $p) => $this->present($p))->all(),
            'facets' => $facets,
            'total'  => $total,
        ];
    }

    public function suggest(string $prefix, int $limit): array
    {
        return SearchProduct::where('status', 'published')
            ->where('name', 'like', $prefix.'%')
            ->orderBy('name')
            ->limit($limit)
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    private function present(SearchProduct $p): array
    {
        return [
            'product_uuid' => $p->product_uuid,
            'name'         => $p->name,
            'slug'         => $p->slug,
            'category'     => $p->category_uuid ? ['uuid' => $p->category_uuid, 'name' => $p->category_name] : null,
            'attributes'   => $p->attributes,
            'price_min'    => $p->price_min,
            'price_max'    => $p->price_max,
        ];
    }
}
