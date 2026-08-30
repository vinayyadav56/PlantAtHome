<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\Legal\Concerns\HasLegalUuid;
use Marvel\Database\Models\User;

/** One queue for findings raised by a risk, a compliance item or a document. */
class LegalCorrectiveAction extends Model
{
    use HasLegalUuid;

    protected $table = 'legal_corrective_actions';
    protected $guarded = ['id'];
    protected $casts = ['due_date' => 'date', 'completed_at' => 'datetime'];

    public const SOURCES = ['risk', 'compliance', 'document'];
    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];
    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    protected static function booted(): void
    {
        static::created(fn (self $a) => LegalAudit::record('action_created', null, null, [
            'source' => $a->source_type, 'source_id' => $a->source_id, 'title' => $a->title,
        ]));
        static::updated(function (self $a) {
            if (array_key_exists('status', $a->getChanges())) {
                LegalAudit::record('action_status_changed', null, null, [
                    'title' => $a->title, 'status' => $a->status,
                ]);
            }
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isOverdue(): bool
    {
        return $this->due_date
            && ! in_array($this->status, ['done', 'cancelled'], true)
            && $this->due_date->isPast();
    }
}
