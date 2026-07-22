<?php

namespace Tests\Unit\Marketing;

use App\Modules\Marketing\Domain\Sql\SqlSelectGuard;
use App\Shared\Application\DomainActionException;
use Tests\TestCase;

/**
 * The security centrepiece: operator SQL must prove it is a single read-only
 * SELECT. These cases pin the guard so a regression can't quietly reopen the
 * door to writes, DDL, stacked statements, or file/timing abuse.
 */
class SqlSelectGuardTest extends TestCase
{
    private SqlSelectGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new SqlSelectGuard();
    }

    /** @dataProvider validQueries */
    public function test_accepts_safe_selects(string $sql): void
    {
        $this->guard->validate($sql); // no exception == pass
        $this->assertTrue(true);
    }

    public static function validQueries(): array
    {
        return [
            'simple'            => ["SELECT id, name, email, phone, city FROM users WHERE city='Delhi' AND is_verified=1"],
            'trailing semicolon' => ['SELECT id FROM users;'],
            'join + aggregate'  => ['SELECT u.id, COUNT(o.id) FROM users u LEFT JOIN orders o ON o.user_id = u.id GROUP BY u.id'],
            'semicolon inside a string literal is not a 2nd statement' => ["SELECT id FROM users WHERE name = 'a; DROP TABLE x'"],
            'keyword inside a string literal is harmless' => ["SELECT id FROM users WHERE note = 'please DELETE me'"],
            'lowercase select'  => ['select id from users'],
        ];
    }

    /** @dataProvider dangerousQueries */
    public function test_rejects_dangerous_sql(string $sql): void
    {
        $this->expectException(DomainActionException::class);
        $this->guard->validate($sql);
    }

    public static function dangerousQueries(): array
    {
        return [
            'insert'          => ['INSERT INTO users (name) VALUES ("x")'],
            'insert no INTO'  => ['INSERT users SET name = "x"'],
            'update'          => ['UPDATE users SET is_admin = 1'],
            'delete'          => ['DELETE FROM users'],
            'drop'            => ['DROP TABLE users'],
            'truncate'        => ['TRUNCATE users'],
            'alter'           => ['ALTER TABLE users ADD COLUMN x INT'],
            'create'          => ['CREATE TABLE evil (id INT)'],
            'replace'         => ['REPLACE INTO users VALUES (1)'],
            'grant'           => ['GRANT ALL ON *.* TO admin'],
            'stacked'         => ['SELECT id FROM users; DROP TABLE users'],
            'stacked w/ trailing ;' => ['SELECT 1; DELETE FROM users;'],
            'not a select'    => ['SHOW TABLES'],
            'cte leading'     => ['WITH x AS (SELECT 1) SELECT * FROM x'],
            'select into outfile' => ["SELECT * FROM users INTO OUTFILE '/tmp/x'"],
            'sleep timing'    => ['SELECT SLEEP(10)'],
            'benchmark'       => ['SELECT BENCHMARK(1000000, MD5(1))'],
            'load_file'       => ["SELECT LOAD_FILE('/etc/passwd')"],
            'comment-hidden stacked' => ['SELECT 1 /* */ ; /* */ DROP TABLE users'],
            'set session'     => ['SET SESSION x = 1'],
            'empty'           => ['   '],
            // regressions for review findings:
            'mysql executable comment' => ['SELECT 1 /*!50000 UNION SELECT password FROM users */'],
            'exec comment bare'        => ['SELECT /*! SLEEP(5) */ 1'],
            'sys_exec udf (underscore evades \\b)' => ["SELECT sys_exec('id')"],
            'sys_eval udf'             => ["SELECT sys_eval('whoami')"],
        ];
    }

    /** With an allow-list configured, joins/commas/schemas can't reach other tables. */
    public function test_allow_list_blocks_join_bypasses(): void
    {
        config(['marketing.audience.allowed_tables' => ['users']]);

        $blocked = [
            'comma join'      => 'SELECT t.token FROM users, personal_access_tokens t',
            'straight_join'   => 'SELECT t.token FROM users STRAIGHT_JOIN personal_access_tokens t',
            'inner join'      => 'SELECT * FROM users JOIN personal_access_tokens ON 1=1',
            'schema qualified' => 'SELECT * FROM otherdb.users',
        ];
        foreach ($blocked as $label => $sql) {
            try {
                $this->guard->validate($sql);
                $this->fail("expected rejection for: {$label}");
            } catch (DomainActionException $e) {
                $this->assertSame('AUDIENCE_SQL_TABLE_NOT_ALLOWED', $e->errorCode(), $label);
            }
        }

        // The allow-listed table itself still passes.
        $this->guard->validate('SELECT id, email FROM users WHERE id > 0');
        $this->assertTrue(true);
    }

    public function test_error_carries_a_machine_code_and_field(): void
    {
        try {
            $this->guard->validate('DELETE FROM users');
            $this->fail('expected rejection');
        } catch (DomainActionException $e) {
            $this->assertSame(422, $e->httpStatus());
            $this->assertStringStartsWith('AUDIENCE_SQL_', $e->errorCode());
            $this->assertSame('sql_query', $e->field());
        }
    }
}
