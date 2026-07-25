<?php

namespace Tests\Feature\ImageBatches;

use Marvel\Http\Controllers\SystemController;

/**
 * system/run-command exists because the scheduler swallows per-event
 * exceptions: a sweep can throw every minute for hours while `schedule:run`
 * still reports healthy, and the hosts this runs on have no shell. It must
 * surface a throwing command's exception rather than hide it — and it must
 * stay a strict allowlist, never a general command runner.
 */
final class RunCommandDiagnosticTest extends ImageBatchTestCase
{
    public function test_it_runs_an_allowlisted_command_and_returns_its_output(): void
    {
        $this->admin();

        $this->postJson('/api/system/run-command', ['command' => 'images:sweep-batches'])
            ->assertOk()
            ->assertJson(['ok' => true, 'command' => 'images:sweep-batches', 'exit_code' => 0]);
    }

    public function test_it_refuses_anything_off_the_allowlist(): void
    {
        $this->admin();

        foreach (['migrate:fresh', 'db:wipe', 'tinker', ''] as $command) {
            $this->postJson('/api/system/run-command', ['command' => $command])
                ->assertStatus(422)
                ->assertJson(['ok' => false]);
        }
    }

    public function test_the_allowlist_holds_only_idempotent_maintenance_commands(): void
    {
        // A guard on the guard: this list is the blast radius of the endpoint,
        // so widening it should be a deliberate edit, not a drive-by.
        $this->assertSame([
            'schedule:clear-cache',
            'images:sweep-batches',
            'content:sweep-batches',
            'inventory:release-expired',
            'outbox:relay',
            'marketing:dispatch-due',
        ], array_keys(SystemController::RUNNABLE_COMMANDS));
    }

    /**
     * Every allowlisted signature must map to a class that exists AND actually
     * declares that signature. Without this the endpoint silently degrades into
     * reporting "command does not exist" — indistinguishable from the real
     * scheduler fault it is built to diagnose.
     */
    public function test_every_allowlisted_command_resolves_to_its_real_class(): void
    {
        foreach (SystemController::RUNNABLE_COMMANDS as $signature => $class) {
            $this->assertTrue(class_exists($class), "{$class} does not exist");

            $ref      = new \ReflectionClass($class);
            $declared = $ref->newInstanceWithoutConstructor();

            // Commands declare their name via $signature (modern) or $name
            // (older framework commands, e.g. ScheduleClearCacheCommand).
            $names = [];
            foreach (['signature', 'name'] as $prop) {
                if (!$ref->hasProperty($prop)) {
                    continue;
                }
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $names[] = (string) $p->getValue($declared);
            }

            $this->assertTrue(
                (bool) array_filter($names, fn ($n) => str_starts_with($n, $signature)),
                "{$class} does not declare the command name {$signature} (found: "
                    . implode(', ', array_filter($names)) . ')'
            );
        }
    }
}
