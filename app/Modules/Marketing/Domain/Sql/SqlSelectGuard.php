<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain\Sql;

use App\Shared\Application\DomainActionException;

/**
 * Defence-in-depth validator for operator-authored audience SQL. The audience
 * builder lets an admin (marketing.manage) run arbitrary SELECTs to segment
 * users — so before a query is ever stored or executed it must prove it is a
 * SINGLE, read-only SELECT and nothing else.
 *
 * Strategy (all on a copy with string literals + comments removed, so keywords
 * hiding in a literal can neither trip nor evade a check):
 *   1. non-empty and within a sane length,
 *   2. exactly one statement (no stacked queries),
 *   3. starts with SELECT (no CTE/PRAGMA/other leading verb),
 *   4. contains no write / DDL / privilege / file / lock / timing keyword,
 *   5. (optional) only reads from an allow-listed set of tables.
 *
 * Execution adds further guards (row cap, statement timeout, rolled-back
 * transaction, derived-table wrapping) — see AudienceQueryRunner. A violation
 * throws a 422 DomainActionException with a machine code the UI surfaces.
 */
final class SqlSelectGuard
{
    private const MAX_LENGTH = 20000;

    /**
     * Keywords that must never appear in a read-only audience query. Matched as
     * whole words on the literal-stripped, upper-cased SQL.
     *
     * @var string[]
     */
    private const BLOCKLIST = [
        // writes + DDL
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
        'REPLACE', 'RENAME', 'MERGE', 'UPSERT',
        // privileges / admin
        'GRANT', 'REVOKE', 'SET', 'FLUSH', 'RESET', 'SHUTDOWN', 'KILL',
        // procedural / dynamic
        'CALL', 'EXEC', 'EXECUTE', 'PREPARE', 'DEALLOCATE', 'HANDLER', 'DO',
        'DECLARE', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'USE',
        // file / lock / recon / timing abuse
        'INTO', 'OUTFILE', 'DUMPFILE', 'LOAD', 'LOAD_FILE', 'INFILE',
        'LOCK', 'UNLOCK', 'GET_LOCK', 'SLEEP', 'BENCHMARK', 'ATTACH', 'PRAGMA',
    ];

    /**
     * Dangerous tokens that contain underscores, so `\bWORD\b` word-boundary
     * matching (which treats `_` as a word char) would never catch them —
     * matched as plain substrings. Command-exec UDFs are the classic case.
     *
     * @var string[]
     */
    private const SUBSTRING_BLOCKLIST = [
        'SYS_EXEC', 'SYS_EVAL', 'SYS_BINEVAL', 'LIB_MYSQLUDF', 'INTO OUTFILE', 'INTO DUMPFILE',
    ];

    /**
     * @throws DomainActionException when $sql is not a safe single SELECT
     */
    public function validate(string $sql): void
    {
        $raw = trim($sql);

        if ($raw === '') {
            throw $this->reject('The audience query is empty.', 'AUDIENCE_SQL_EMPTY');
        }
        if (mb_strlen($raw) > self::MAX_LENGTH) {
            throw $this->reject('The audience query is too long.', 'AUDIENCE_SQL_TOO_LONG');
        }

        // MySQL EXECUTABLE comments (/*! ... */ and /*!50000 ... */) are run by
        // the server but look inert to a comment stripper — anything inside would
        // bypass every check below yet still execute. Reject outright.
        if (preg_match('/\/\*!/', $raw)) {
            throw $this->reject('MySQL executable comments are not allowed.', 'AUDIENCE_SQL_EXECUTABLE_COMMENT');
        }

        // Structure-only view: comments gone, string/backtick literals neutralised.
        $stripped = $this->stripLiteralsAndComments($raw);
        $normalized = trim($stripped);

        // One optional trailing ';' is fine; anything after it (or an internal
        // ';') means stacked statements — reject.
        $normalized = rtrim($normalized);
        if (str_ends_with($normalized, ';')) {
            $normalized = rtrim(substr($normalized, 0, -1));
        }
        if (str_contains($normalized, ';')) {
            throw $this->reject('Only a single SQL statement is allowed.', 'AUDIENCE_SQL_MULTIPLE_STATEMENTS');
        }
        if ($normalized === '') {
            throw $this->reject('The audience query is empty.', 'AUDIENCE_SQL_EMPTY');
        }

        $upper = strtoupper($normalized);

        if (! preg_match('/^\s*SELECT\b/', $upper)) {
            throw $this->reject('Only SELECT queries are allowed.', 'AUDIENCE_SQL_NOT_SELECT', 'sql_query');
        }

        foreach (self::BLOCKLIST as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/', $upper)) {
                throw $this->reject(
                    "The keyword \"{$keyword}\" is not allowed in an audience query.",
                    'AUDIENCE_SQL_FORBIDDEN_KEYWORD',
                    'sql_query',
                );
            }
        }

        foreach (self::SUBSTRING_BLOCKLIST as $token) {
            if (str_contains($upper, $token)) {
                throw $this->reject(
                    'The audience query contains a disallowed construct.',
                    'AUDIENCE_SQL_FORBIDDEN_KEYWORD',
                    'sql_query',
                );
            }
        }

        $this->assertAllowedTables($normalized);
    }

    /**
     * When an allow-list is configured, every table referenced after FROM/JOIN
     * (including comma-joins) must be in it, and schema-qualified references
     * (db.table) are rejected so the list can't be dodged via another schema.
     *
     * NOTE: this is a convenience filter, NOT the security boundary. Regex
     * table-extraction on raw SQL is fundamentally unsound (sub-selects, CTE-less
     * derived tables, etc.). The real containment for the audience builder is a
     * dedicated least-privilege, read-only DB user GRANTed SELECT only on the
     * intended tables — see docs/ARCHITECTURE_MARKETING.md §13.
     */
    private function assertAllowedTables(string $normalized): void
    {
        $allowed = (array) config('marketing.audience.allowed_tables', []);
        if ($allowed === []) {
            return;
        }
        $allowedLower = array_map('strtolower', $allowed);

        // Old-style comma joins (`FROM a, b`) can't be validated by a
        // name-after-keyword scan, and a blanket comma match would wrongly flag
        // SELECT-list commas — so reject a comma anywhere in the FROM clause
        // region (from the FROM keyword to the next major clause) outright.
        if (preg_match('/\bFROM\b(.*?)(?:\bWHERE\b|\bGROUP\b|\bHAVING\b|\bORDER\b|\bLIMIT\b|\bUNION\b|$)/is', $normalized, $fromClause)
            && str_contains($fromClause[1] ?? '', ',')) {
            throw $this->reject(
                'Comma joins are not permitted with a table allow-list; use explicit JOIN … ON.',
                'AUDIENCE_SQL_TABLE_NOT_ALLOWED',
                'sql_query',
            );
        }

        // Every identifier after FROM / (STRAIGHT_)JOIN. STRAIGHT_JOIN is listed
        // explicitly because `\bJOIN` won't match inside it (the preceding `_`
        // is a word char, so no boundary).
        preg_match_all('/(?:\bFROM|\bSTRAIGHT_JOIN|\bJOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_\.]*)`?/i', $normalized, $matches);
        foreach ($matches[1] ?? [] as $table) {
            if (str_contains($table, '.')) {
                throw $this->reject(
                    "Schema-qualified table references (\"{$table}\") are not permitted.",
                    'AUDIENCE_SQL_TABLE_NOT_ALLOWED',
                    'sql_query',
                );
            }
            if (! in_array(strtolower($table), $allowedLower, true)) {
                throw $this->reject(
                    "Reading from \"{$table}\" is not permitted.",
                    'AUDIENCE_SQL_TABLE_NOT_ALLOWED',
                    'sql_query',
                );
            }
        }
    }

    /**
     * Remove SQL comments (block, -- and #) and replace every string / backtick
     * literal with a neutral token, so structural checks see only SQL grammar.
     */
    private function stripLiteralsAndComments(string $sql): string
    {
        // Block comments /* ... */ (non-greedy, dot-matches-newline).
        $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
        // Line comments -- ... and # ... to end of line.
        $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;
        $sql = preg_replace('/#[^\n]*/', ' ', $sql) ?? $sql;

        // Single-quoted strings (handles '' and \' escapes).
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $sql) ?? $sql;
        // Double-quoted strings.
        $sql = preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/s', '""', $sql) ?? $sql;
        // Backtick identifiers → neutral token (so a column named `update`
        // cannot trip the blocklist, while table extraction still works enough).
        $sql = preg_replace('/`(?:[^`]|``)*`/s', '`_id`', $sql) ?? $sql;

        return $sql;
    }

    private function reject(string $message, string $code, string $field = 'sql_query'): DomainActionException
    {
        return DomainActionException::unprocessable($message, $code, $field);
    }
}
