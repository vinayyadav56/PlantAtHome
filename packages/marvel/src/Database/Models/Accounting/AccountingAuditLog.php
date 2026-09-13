<?php

namespace Marvel\Database\Models\Accounting;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/** Append-only who/what/when/before/after/reason trail for every financial mutation (spec §32). */
class AccountingAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'acc_audit_log';
    public $guarded = [];
    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    /**
     * Never throws: an audit failure must not roll back money, but it is logged loudly.
     * Actor/IP come from the current request when there is one, else "system".
     */
    public static function record(string $type, int|string $id, string $action, ?array $before = null, ?array $after = null, ?string $reason = null, ?string $reference = null, ?string $actor = null): void
    {
        try {
            $user = auth()->user();
            $req = app()->bound('request') ? request() : null;
            static::create([
                'auditable_type' => $type,
                'auditable_id'   => (string) $id,
                'action'         => $action,
                'before'         => $before,
                'after'          => $after,
                'reason'         => $reason,
                'reference'      => $reference,
                'actor_type'     => $actor ? (str_starts_with($actor, 'system') ? 'system' : 'user') : ($user ? 'user' : 'system'),
                'actor_id'       => $actor ?? ($user?->id ? (string) $user->id : 'system'),
                'ip'             => $req?->ip(),
                'user_agent'     => $req ? mb_substr((string) $req->userAgent(), 0, 255) : null,
                'created_at'     => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('acc_audit_log write failed', ['type' => $type, 'id' => $id, 'action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
