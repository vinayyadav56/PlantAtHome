<?php

namespace Marvel\Services\TestDataCleanup;

/**
 * What a cleanup WILL do, computed before anything is deleted.
 *
 * A plan is an ordered list of steps; each step is one table plus the exact primary-key ids
 * (or a scoping closure) that will be removed. Order matters — children first — because the
 * schema's foreign keys are a mix of CASCADE, RESTRICT and (for every table born in the app
 * migrations) no constraint at all, so a wrong order either blocks or orphans.
 */
class CleanupPlan
{
    /** @var array<int, array{table: string, ids: array, column: string, note: ?string}> */
    public array $steps = [];

    public array $warnings = [];

    /**
     * References that must SURVIVE as NULL rather than be deleted — e.g. a product proposed by
     * a vendor stays in the catalog (the platform owns it) with its proposer cleared.
     * @var array<int, array{table: string, column: string, ids: array}>
     */
    public array $nullifications = [];

    public function __construct(public string $module, public array $scope = [])
    {
    }

    /**
     * Add a deletion step. `$ids` are the values of `$column` to delete by — resolved NOW so
     * the preview and the execute operate on exactly the same rows.
     */
    public function step(string $table, array $ids, string $column = 'id', ?string $note = null): self
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($v) => $v !== null && $v !== '')));
        if ($ids) {
            $this->steps[] = compact('table', 'ids', 'column', 'note');
        }
        return $this;
    }

    /** Clear a foreign reference instead of deleting the referencing row. */
    public function nullify(string $table, string $column, array $ids): self
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids) {
            $this->nullifications[] = compact('table', 'column', 'ids');
        }
        return $this;
    }

    /** A non-fatal caution surfaced to the operator in the preview. */
    public function warn(string $message): self
    {
        $this->warnings[] = $message;
        return $this;
    }

    public function tables(): array
    {
        return array_values(array_unique(array_column($this->steps, 'table')));
    }

    public function counts(): array
    {
        $out = [];
        foreach ($this->steps as $s) {
            $out[$s['table']] = ($out[$s['table']] ?? 0) + count($s['ids']);
        }
        return $out;
    }

    public function totalRows(): int
    {
        return array_sum($this->counts());
    }

    public function isEmpty(): bool
    {
        return $this->totalRows() === 0;
    }
}
