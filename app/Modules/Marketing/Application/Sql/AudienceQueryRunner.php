<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\Sql;

use App\Modules\Marketing\Application\DTO\AudiencePreview;
use App\Modules\Marketing\Domain\Sql\SqlSelectGuard;
use App\Shared\Application\DomainActionException;
use Illuminate\Database\ConnectionInterface;

/**
 * Executes an operator's audience SELECT safely:
 *   • the SqlSelectGuard proves it is a single read-only SELECT first,
 *   • the query is never run bare — it's wrapped as a derived table so a
 *     trailing clause can't escape, an exact COUNT(*) is taken from the same
 *     wrapper, and rows are LIMITed at the DB (not in PHP),
 *   • a MySQL MAX_EXECUTION_TIME optimizer hint caps runtime (a harmless inline
 *     comment on other engines; MariaDB ignores it — see the deploy note),
 *   • the query runs inside a transaction that is always rolled back (belt-and-
 *     braces for transactional DML).
 *
 * These are defence in depth, NOT the trust boundary. The rollback only undoes
 * transactional row changes — it can't undo a filesystem write (SELECT … INTO
 * OUTFILE) or an advisory lock. The guard blocks those keywords, but the real
 * containment for running operator SQL is a dedicated least-privilege, read-only
 * DB user (no FILE / write / DDL / EXECUTE grants). See docs §13.
 */
final class AudienceQueryRunner
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SqlSelectGuard $guard,
    ) {
    }

    /** Preview: cap rows at previewRows, but return the exact total count. */
    public function preview(string $sql, ?int $previewRows = null): AudiencePreview
    {
        $limit = $previewRows ?? (int) config('marketing.audience.preview_rows', 100);

        return $this->run($sql, $limit, capTruncation: false);
    }

    /**
     * Snapshot: freeze up to max_rows rows for a saved version. Sets truncated
     * when the true count exceeds the cap.
     */
    public function snapshot(string $sql, ?int $maxRows = null): AudiencePreview
    {
        $cap = $maxRows ?? (int) config('marketing.audience.max_rows', 100000);

        return $this->run($sql, $cap, capTruncation: true);
    }

    private function run(string $sql, int $limit, bool $capTruncation): AudiencePreview
    {
        $this->guard->validate($sql);

        $inner = $this->stripTrailingSemicolon($sql);
        $ms = (int) config('marketing.audience.max_exec_ms', 15000);
        $hint = "/*+ MAX_EXECUTION_TIME({$ms}) */";
        $limit = max(0, $limit);

        // Bound integers are interpolated (they're app-controlled, not user
        // input); the operator SQL is a derived table, never string-concatenated
        // into a clause.
        $countSql = "SELECT {$hint} COUNT(*) AS aggregate FROM ({$inner}) AS _pah_audience";
        $rowsSql  = "SELECT {$hint} * FROM ({$inner}) AS _pah_audience LIMIT {$limit}";

        $started = microtime(true);
        $this->db->beginTransaction();
        try {
            $total = (int) ($this->db->selectOne($countSql)->aggregate ?? 0);
            $rawRows = $limit > 0 ? $this->db->select($rowsSql) : [];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw DomainActionException::unprocessable(
                'The audience query could not be executed: '.$this->cleanDbError($e->getMessage()),
                'AUDIENCE_SQL_EXECUTION_FAILED',
                'sql_query',
            );
        } finally {
            // A read-only query needs no commit; always undo to guarantee no side effect.
            if ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
        }
        $executionMs = (int) round((microtime(true) - $started) * 1000);

        $rows = array_map(fn ($row) => (array) $row, $rawRows);
        $columns = $rows !== [] ? array_keys($rows[0]) : [];
        $truncated = $capTruncation && $total > $limit;

        return new AudiencePreview($rows, $total, $columns, $executionMs, $truncated);
    }

    private function stripTrailingSemicolon(string $sql): string
    {
        $sql = rtrim(trim($sql));

        return str_ends_with($sql, ';') ? rtrim(substr($sql, 0, -1)) : $sql;
    }

    /** Keep the DB's message but drop our wrapper alias noise from it. */
    private function cleanDbError(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return mb_substr(trim($message), 0, 300);
    }
}
