<?php

namespace App\Modules\Search\Application;

use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\Product as CatalogProduct;
use App\Modules\Search\Infrastructure\EsSearchIndex;
use App\Modules\Search\Infrastructure\MysqlSearchIndex;
use App\Modules\Serviceability\Application\CoverageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Search read-model orchestration (Section 12). Writes the denormalized product
 * document into the MySQL projection (always) and Elasticsearch (when available);
 * reads from ES when up, else transparently degrades to MySQL. City-aware
 * availability is composed from Serviceability at query time.
 */
class SearchService
{
    public function __construct(
        private readonly EsSearchIndex $es,
        private readonly MysqlSearchIndex $mysql,
        private readonly CoverageService $coverage,
    ) {
    }

    /** (Re)index one product from the catalog into the search projection(s). */
    public function indexProduct(string $productUuid): void
    {
        $product = CatalogProduct::where('uuid', $productUuid)
            ->with(['category', 'variants', 'attributeValues.attribute'])
            ->first();
        if (! $product) {
            return;
        }
        // Only published products are searchable; drafts/archived are removed.
        if ($product->status !== ProductStatus::PUBLISHED) {
            $this->removeProduct($productUuid);

            return;
        }

        $doc = $this->buildDoc($product);
        $this->mysql->upsert($doc);
        if ($this->es->available()) {
            try {
                $this->es->upsert($doc);
            } catch (\Throwable $e) {
                Log::warning('[search] ES upsert failed, projection still updated', ['error' => $e->getMessage()]);
            }
        }
    }

    public function removeProduct(string $productUuid): void
    {
        $this->mysql->remove($productUuid);
        if ($this->es->available()) {
            try {
                $this->es->remove($productUuid);
            } catch (\Throwable) {
            }
        }
    }

    /** Rebuild the whole projection from the catalog. Returns the count indexed. */
    public function reindexAll(): int
    {
        $count = 0;
        CatalogProduct::where('status', ProductStatus::PUBLISHED)->pluck('uuid')->each(function ($uuid) use (&$count) {
            $this->indexProduct($uuid);
            $count++;
        });

        return $count;
    }

    /**
     * @return array{hits:array, facets:array, total:int, backend:string, city_filtered:bool}
     */
    public function search(string $query, array $filters, ?string $city, int $limit = 20): array
    {
        [$backend, $result] = $this->withDegrade(fn ($index) => $index->search($query, $filters, max($limit, 50)));

        $cityFiltered = false;
        if ($city && $this->serviceabilityReady()) {
            $result['hits'] = array_values(array_filter(
                $result['hits'],
                function ($hit) use ($city) {
                    try {
                        return $this->coverage->productAvailability($hit['product_uuid'], $city)['available'] ?? false;
                    } catch (\Throwable $e) {
                        // A stale projection row or bad city must never abort the whole
                        // search — treat an un-answerable hit as not available.
                        return false;
                    }
                },
            ));
            // Facets + total must reflect only the servable set, not the pre-filter matches.
            $result['facets'] = $this->facetsFromHits($result['hits']);
            $result['total'] = count($result['hits']);
            $cityFiltered = true;
        }

        $result['hits'] = array_slice($result['hits'], 0, $limit);

        return array_merge($result, ['backend' => $backend, 'city_filtered' => $cityFiltered]);
    }

    /** Recompute the category facet from an already-filtered hit set. */
    private function facetsFromHits(array $hits): array
    {
        $byCat = [];
        foreach ($hits as $hit) {
            $cat = $hit['category'] ?? null;
            if (! $cat || empty($cat['uuid'])) {
                continue;
            }
            $uuid = $cat['uuid'];
            $byCat[$uuid] ??= ['uuid' => $uuid, 'name' => $cat['name'] ?? null, 'count' => 0];
            $byCat[$uuid]['count']++;
        }

        return ['category' => array_values($byCat)];
    }

    public function suggest(string $prefix, int $limit = 10): array
    {
        [, $result] = $this->withDegrade(fn ($index) => ['s' => $index->suggest($prefix, $limit)]);

        return $result['s'];
    }

    /* ── internals ─────────────────────────────────────────────────────────── */

    /** Run against ES if up, else MySQL. Returns [backend, result]. */
    private function withDegrade(callable $run): array
    {
        if ($this->es->available()) {
            try {
                return ['elasticsearch', $run($this->es)];
            } catch (\Throwable $e) {
                Log::warning('[search] ES query failed, falling back to MySQL', ['error' => $e->getMessage()]);
            }
        }

        return ['mysql', $run($this->mysql)];
    }

    private function buildDoc(CatalogProduct $product): array
    {
        $attributes = [];
        $attrValues = [];
        foreach ($product->attributeValues as $av) {
            $code = $av->attribute?->code;
            $value = is_array($av->value) ? ($av->value['value'] ?? null) : $av->value;
            if ($code !== null) {
                $attributes[$code] = $value;
                if (is_scalar($value)) {
                    $attrValues[] = (string) $value;
                }
            }
        }

        [$min, $max] = $this->priceRange($product);

        return [
            'product_uuid'  => $product->uuid,
            'name'          => $product->name,
            'slug'          => $product->slug,
            'category_uuid' => $product->category?->uuid,
            'category_name' => $product->category?->name,
            'attributes'    => $attributes,
            'keywords'      => trim(implode(' ', array_filter([
                $product->name, $product->botanical_name, $product->hindi_name,
                $product->category?->name, implode(' ', $attrValues),
            ]))),
            'price_min'     => $min,
            'price_max'     => $max,
            'status'        => $product->status,
        ];
    }

    /** @return array{0:?float,1:?float} */
    private function priceRange(CatalogProduct $product): array
    {
        if (! Schema::hasTable('pricing_base_prices')) {
            return [null, null];
        }
        $variantUuids = $product->variants->pluck('uuid')->all();
        if ($variantUuids === []) {
            return [null, null];
        }
        $row = DB::table('pricing_base_prices')
            ->where('sellable_type', 'variant')
            ->whereIn('sellable_uuid', $variantUuids)
            ->selectRaw('MIN(amount) as mn, MAX(amount) as mx')
            ->first();

        return [$row?->mn !== null ? (float) $row->mn : null, $row?->mx !== null ? (float) $row->mx : null];
    }

    private function serviceabilityReady(): bool
    {
        return Schema::hasTable('svc_vendor_areas');
    }
}
