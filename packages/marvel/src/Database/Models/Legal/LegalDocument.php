<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marvel\Database\Models\Legal\Concerns\HasLegalUuid;
use Marvel\Database\Models\User;

class LegalDocument extends Model
{
    use HasLegalUuid;
    use SoftDeletes;

    protected $table = 'legal_documents';
    protected $guarded = ['id'];
    protected $casts = [
        'legal_review_required' => 'bool',
        'tags' => 'array',
        'effective_date' => 'date',
        'next_review_date' => 'date',
        'last_reviewed_at' => 'date',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public const VISIBILITIES = ['public', 'customer', 'vendor', 'employee', 'internal', 'restricted'];
    public const REVIEW_FREQUENCIES = ['monthly', 'quarterly', 'half_yearly', 'annually', 'custom'];

    protected static function booted(): void
    {
        // Model-event audit so console/seeder writes are covered too.
        static::created(fn (self $d) => LegalAudit::record('document_created', $d->id, null, ['title' => $d->title, 'code' => $d->document_code]));
        static::updated(function (self $d) {
            $changes = $d->getChanges();
            unset($changes['updated_at']);
            if ($changes !== []) {
                LegalAudit::record('document_updated', $d->id, null, [], array_intersect_key($d->getOriginal(), $changes), $changes);
            }
        });
        static::deleted(fn (self $d) => LegalAudit::record('document_deleted', $d->id));
    }

    public function type()
    {
        return $this->belongsTo(LegalDocumentType::class, 'type_id');
    }

    public function category()
    {
        return $this->belongsTo(LegalCategory::class, 'category_id');
    }

    public function versions()
    {
        return $this->hasMany(LegalDocumentVersion::class, 'document_id')
            ->orderByDesc('version_major')->orderByDesc('version_minor');
    }

    public function currentVersion()
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'current_version_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** The version being worked on (newest non-superseded/non-published), if any. */
    public function workingVersion(): ?LegalDocumentVersion
    {
        return $this->versions()
            ->whereNotIn('status', ['published', 'superseded'])
            ->first();
    }
}
