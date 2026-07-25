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
            'images:sweep-batches',
            'content:sweep-batches',
            'inventory:release-expired',
            'outbox:relay',
            'marketing:dispatch-due',
        ], SystemController::RUNNABLE_COMMANDS);
    }
}
