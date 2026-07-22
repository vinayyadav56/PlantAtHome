<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\DTO;

/**
 * The result of running an audience query: the (possibly capped) rows, the exact
 * total, the returned columns and how long it took. Immutable — a value object
 * handed from the query runner to the service/API.
 */
final class AudiencePreview
{
    /**
     * @param array<int, array<string,mixed>> $rows
     * @param string[] $columns
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $totalCount,
        public readonly array $columns,
        public readonly int $executionMs,
        public readonly bool $truncated = false,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'rows'         => $this->rows,
            'total_count'  => $this->totalCount,
            'returned'     => count($this->rows),
            'columns'      => $this->columns,
            'execution_ms' => $this->executionMs,
            'truncated'    => $this->truncated,
        ];
    }
}
