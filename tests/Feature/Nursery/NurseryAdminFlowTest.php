<?php

namespace Tests\Feature\Nursery;

use Illuminate\Support\Facades\DB;

/**
 * End-to-end admin + owner flow: create → approve → commission → owner files a
 * withdrawal (idempotent, balance-capped) → admin approves (balance debited,
 * ledger written) → foreign owner and guests are rejected at the seam.
 */
class NurseryAdminFlowTest extends NurseryTestCase
{
    public function test_full_nursery_and_withdrawal_flow(): void
    {
        // Guests never see the vendor surface.
        $this->getJson('/api/v1/nurseries')->assertStatus(401);

        $admin = $this->bearer($this->accessToken('admin@plantathome.test'));

        // ── Create: lands in pending with an empty balance ────────────────
        $created = $this->postJson('/api/v1/nurseries', [
            'name'        => 'Green Leaf Nursery',
            'slug'        => 'green-leaf',
            'description' => 'Test vendor',
        ], $admin);
        $created->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.balance.current_balance', 0);
        $uuid = $created->json('data.uuid');
        $this->assertNotEmpty($uuid);

        // ── List filters by status ────────────────────────────────────────
        $list = $this->getJson('/api/v1/nurseries?status=pending', $admin);
        $list->assertStatus(200);
        $this->assertNotNull(collect($list->json('data'))->firstWhere('uuid', $uuid));

        // ── Show resolves by uuid AND by slug (admin drill-down uses slug) ─
        $this->getJson("/api/v1/nurseries/{$uuid}", $admin)
            ->assertStatus(200)->assertJsonPath('data.uuid', $uuid);
        $this->getJson('/api/v1/nurseries/green-leaf', $admin)
            ->assertStatus(200)->assertJsonPath('data.uuid', $uuid);
        $this->getJson('/api/v1/nurseries/no-such-nursery', $admin)->assertStatus(404);

        // ── Approve with a commission; idempotent second call ─────────────
        $this->postJson("/api/v1/nurseries/{$uuid}/approve", ['commission_rate' => 12.5], $admin)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.commission_rate', 12.5);
        $this->postJson("/api/v1/nurseries/{$uuid}/approve", [], $admin)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        // ── Change commission ─────────────────────────────────────────────
        $this->patchJson("/api/v1/nurseries/{$uuid}/commission", ['commission_rate' => 15], $admin)
            ->assertStatus(200)
            ->assertJsonPath('data.commission_rate', 15);

        // ── Owner flow: scope owner.a to this nursery + seed a balance ────
        DB::table('identity_users')->where('email', 'owner.a@plantathome.test')->update(['nursery_id' => $uuid]);
        $internalId = DB::table('nursery_nurseries')->where('uuid', $uuid)->value('id');
        DB::table('nursery_balances')->where('nursery_id', $internalId)->update(['current_balance' => 500]);

        $owner = $this->bearer($this->accessToken('owner.a@plantathome.test'));

        $request = $this->postJson("/api/v1/nurseries/{$uuid}/withdrawals", ['amount' => 100],
            $owner + ['Idempotency-Key' => 'wd-key-1']);
        $request->assertStatus(201)->assertJsonPath('data.status', 'pending');
        $withdrawalUuid = $request->json('data.uuid');

        // Replay with the same key: same withdrawal, no double-file.
        $replay = $this->postJson("/api/v1/nurseries/{$uuid}/withdrawals", ['amount' => 100],
            $owner + ['Idempotency-Key' => 'wd-key-1']);
        $replay->assertStatus(201);
        $this->assertSame($withdrawalUuid, $replay->json('data.uuid'));
        $this->assertSame(1, DB::table('nursery_withdrawals')->count());

        // Over-balance request is refused.
        $this->postJson("/api/v1/nurseries/{$uuid}/withdrawals", ['amount' => 9999], $owner)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'INSUFFICIENT_BALANCE');

        // ── Admin detail view carries the nursery mapper fields ───────────
        $this->getJson("/api/v1/withdrawals/{$withdrawalUuid}", $admin)
            ->assertStatus(200)
            ->assertJsonPath('data.uuid', $withdrawalUuid)
            ->assertJsonPath('data.nursery.uuid', $uuid);

        // ── Admin approves: balance debited + ledger entry written ────────
        $this->postJson("/api/v1/withdrawals/{$withdrawalUuid}/decide", ['action' => 'approve'], $admin)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $balance = DB::table('nursery_balances')->where('nursery_id', $internalId)->first();
        $this->assertSame(400.0, (float) $balance->current_balance);
        $this->assertSame(100.0, (float) $balance->withdrawn_amount);
        $this->assertSame(1, DB::table('nursery_ledger_entries')
            ->where('nursery_id', $internalId)->where('type', 'withdrawal_debit')->count());

        // Deciding a terminal withdrawal again conflicts.
        $this->postJson("/api/v1/withdrawals/{$withdrawalUuid}/decide", ['action' => 'reject'], $admin)
            ->assertStatus(409);

        // ── Isolation: a foreign owner cannot read this nursery's balance ─
        $foreign = $this->bearer($this->accessToken('owner.b@plantathome.test'));
        $this->getJson("/api/v1/nurseries/{$uuid}/balance", $foreign)->assertStatus(403);

        $this->getJson("/api/v1/nurseries/{$uuid}/balance", $owner)
            ->assertStatus(200)
            ->assertJsonPath('data.current_balance', 400);
    }
}
