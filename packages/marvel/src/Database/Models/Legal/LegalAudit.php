<?php

namespace Marvel\Database\Models\Legal;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable governance trail. Written via record() from every mutation path
 * (service methods AND model hooks) — same rationale as integration_audits:
 * controller-level auditing silently misses console/seeder writes.
 */
class LegalAudit extends Model
{
    protected $table = 'legal_document_audits';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['before' => 'array', 'after' => 'array', 'meta' => 'array'];

    public static function record(
        string $event,
        ?int $documentId = null,
        ?int $versionId = null,
        array $meta = [],
        ?array $before = null,
        ?array $after = null,
    ): void {
        try {
            static::create([
                'document_id' => $documentId,
                'version_id' => $versionId,
                'event' => $event,
                'user_id' => auth()->id(),
                'before' => $before,
                'after' => $after,
                'meta' => $meta ?: null,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable) {
            // Best-effort: losing an audit row is bad; failing the governance
            // action an operator just performed because of it is worse.
        }
    }
}
