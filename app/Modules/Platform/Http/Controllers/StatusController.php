<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Shared\Events\OutboxStatus;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2 Phase 12 (observability) — GET /api/v1/platform/status (admin-gated).
 * One call answers "is the async machinery alive?": outbox backlog + oldest
 * pending age, queue depth, failed jobs, and the scheduler heartbeat written
 * every minute by the Kernel schedule. Thresholds are deliberately generous —
 * `healthy` flips false only on states worth paging about.
 */
final class StatusController extends ApiController
{
    /** Heartbeat older than this (seconds) means the cron loop is dead. */
    private const HEARTBEAT_STALE_AFTER = 180;

    /** A pending outbox row older than this (seconds) means the relay stalled. */
    private const OUTBOX_STALE_AFTER = 300;

    public function show(): JsonResponse
    {
        $now = Carbon::now();

        // ── outbox ──
        $pending = (int) DB::table('outbox')->where('status', OutboxStatus::PENDING)->count();
        $failed = (int) DB::table('outbox')->where('status', OutboxStatus::FAILED)->count();
        $oldestPendingAt = DB::table('outbox')->where('status', OutboxStatus::PENDING)->min('created_at');
        $oldestPendingAge = $oldestPendingAt ? $now->diffInSeconds(Carbon::parse($oldestPendingAt)) : 0;

        // ── queue (database driver) ──
        $queueDepth = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : null;
        $failedJobs24h = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->where('failed_at', '>=', $now->copy()->subDay())->count()
            : null;

        // ── scheduler heartbeat ──
        $beatAt = Schema::hasTable('platform_heartbeats')
            ? DB::table('platform_heartbeats')->where('name', 'scheduler')->value('beat_at')
            : null;
        $beatAge = $beatAt ? $now->diffInSeconds(Carbon::parse($beatAt)) : null;

        $schedulerAlive = $beatAge !== null && $beatAge <= self::HEARTBEAT_STALE_AFTER;
        $outboxFlowing = $oldestPendingAge <= self::OUTBOX_STALE_AFTER;

        return $this->ok([
            'healthy' => $schedulerAlive && $outboxFlowing,
            'time' => $now->toIso8601String(),
            'outbox' => [
                'pending' => $pending,
                'failed' => $failed,
                'oldest_pending_age_seconds' => $oldestPendingAge,
                'flowing' => $outboxFlowing,
            ],
            'queue' => [
                'depth' => $queueDepth,
                'failed_jobs_24h' => $failedJobs24h,
            ],
            'scheduler' => [
                'last_beat_at' => $beatAt,
                'beat_age_seconds' => $beatAge,
                'alive' => $schedulerAlive,
            ],
        ]);
    }
}
