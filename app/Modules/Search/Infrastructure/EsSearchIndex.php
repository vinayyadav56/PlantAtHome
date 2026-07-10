<?php

namespace App\Modules\Search\Infrastructure;

use Illuminate\Support\Facades\Http;

/**
 * Elasticsearch backend (Section 12). Dormant unless SEARCH_ES_HOST is set AND
 * reachable — otherwise available() is false and SearchService uses the MySQL
 * fallback. Implements the standard ES DSL (multi_match with fuzziness for typo
 * tolerance, a category terms aggregation for facets, edge-prefix suggestions).
 * All calls are guarded so any ES hiccup degrades gracefully rather than 500ing.
 */
class EsSearchIndex implements SearchIndex
{
    private ?bool $reachable = null;

    public function __construct(
        private readonly string $host,
        private readonly string $index,
        private readonly int $pingTimeout = 2,
    ) {
    }

    public function available(): bool
    {
        if ($this->host === '') {
            return false;
        }
        if ($this->reachable === null) {
            try {
                $this->reachable = Http::timeout($this->pingTimeout)->get($this->host)->successful();
            } catch (\Throwable) {
                $this->reachable = false;
            }
        }

        return $this->reachable;
    }

    public function upsert(array $doc): void
    {
        Http::timeout(5)->put("{$this->host}/{$this->index}/_doc/{$doc['product_uuid']}", $doc);
    }

    public function remove(string $productUuid): void
    {
        Http::timeout(5)->delete("{$this->host}/{$this->index}/_doc/{$productUuid}");
    }

    public function search(string $query, array $filters, int $limit): array
    {
        $must = [];
        if ($query !== '') {
            $must[] = ['multi_match' => ['query' => $query, 'fields' => ['name^3', 'keywords'], 'fuzziness' => 'AUTO']];
        }
        $must[] = ['term' => ['status' => $filters['status'] ?? 'published']];
        if (! empty($filters['category'])) {
            $must[] = ['term' => ['category_uuid' => $filters['category']]];
        }

        $body = [
            'size'  => $limit,
            'query' => ['bool' => ['must' => $must]],
            'aggs'  => ['category' => ['terms' => ['field' => 'category_uuid', 'size' => 50]]],
        ];

        $res = Http::timeout(5)->post("{$this->host}/{$this->index}/_search", $body)->throw()->json();

        $hits = array_map(fn ($h) => $h['_source'], $res['hits']['hits'] ?? []);
        $facets = ['category' => array_map(
            fn ($b) => ['uuid' => $b['key'], 'count' => $b['doc_count']],
            $res['aggregations']['category']['buckets'] ?? [],
        )];

        return ['hits' => $hits, 'facets' => $facets, 'total' => $res['hits']['total']['value'] ?? count($hits)];
    }

    public function suggest(string $prefix, int $limit): array
    {
        $body = ['size' => $limit, 'query' => ['match_phrase_prefix' => ['name' => $prefix]], '_source' => ['name']];
        $res = Http::timeout(5)->post("{$this->host}/{$this->index}/_search", $body)->throw()->json();

        return array_values(array_unique(array_map(fn ($h) => $h['_source']['name'], $res['hits']['hits'] ?? [])));
    }
}
