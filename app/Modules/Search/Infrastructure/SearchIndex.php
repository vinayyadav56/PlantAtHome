<?php

namespace App\Modules\Search\Infrastructure;

/**
 * A pluggable search backend. Elasticsearch is the production index; MySQL is
 * the always-available degraded fallback. Both implement the same contract, so
 * SearchService swaps between them transparently (Section 12 query guardrail).
 */
interface SearchIndex
{
    /** Is this backend reachable right now? */
    public function available(): bool;

    /** @param array<string,mixed> $doc a denormalized product document */
    public function upsert(array $doc): void;

    public function remove(string $productUuid): void;

    /**
     * @param array<string,mixed> $filters e.g. ['category' => uuid, 'status' => …]
     * @return array{hits:array<int,array>, facets:array, total:int}
     */
    public function search(string $query, array $filters, int $limit): array;

    /** @return array<int,string> autocomplete suggestions */
    public function suggest(string $prefix, int $limit): array;
}
