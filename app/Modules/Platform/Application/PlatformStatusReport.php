<?php

namespace App\Modules\Platform\Application;

use App\Shared\Events\OutboxStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The async-machinery health report: outbox backlog + oldest pending age, queue
 * depth, failed jobs, scheduler heartbeat, per-command beats.
 *
 * Extracted from StatusController::show() so the admin command center can
 * consume it SERVER-SIDE (inside its marvel-authenticated aggregator) instead
 * of the admin juggling a second v1-authenticated HTTP call. The controller
 * keeps its route and shape; this class is the single computation.
 */
final class PlatformStatusReport
{
    /** Heartbeat older than this (seconds) means the cron loop is dead. */
    public const HEARTBEAT_STALE_AFTER = 180;

    /** A pending outbox row older than this (seconds) means the relay stalled. */
    public const OUTBOX_STALE_AFTER = 300;

    public function report(): array
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

        // Per-command beats (image/content batch sweepers). The `scheduler` beat
        // only proves the cron loop ticks — an individual command can still be
        // skipped by a stale overlap mutex, which parks in-flight batches.
        $beats = [];
        if (Schema::hasTable('platform_heartbeats')) {
            foreach (DB::table('platform_heartbeats')->where('name', '!=', 'scheduler')->get() as $row) {
                $beats[$row->name] = [
                    'last_beat_at' => $row->beat_at,
                    'age_seconds' => $row->beat_at ? $now->diffInSeconds(Carbon::parse($row->beat_at)) : null,
                ];
            }
        }

        $schedulerAlive = $beatAge !== null && $beatAge <= self::HEARTBEAT_STALE_AFTER;
        $outboxFlowing = $oldestPendingAge <= self::OUTBOX_STALE_AFTER;

        return [
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
            'beats' => $beats,
        ];
    }
}
