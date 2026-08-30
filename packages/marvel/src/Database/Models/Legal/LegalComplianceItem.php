<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marvel\Database\Models\Legal\Concerns\HasLegalUuid;
use Marvel\Database\Models\User;

class LegalComplianceItem extends Model
{
    use HasLegalUuid;
    use SoftDeletes;

    protected $table = 'legal_compliance_items';
    protected $guarded = ['id'];
    protected $casts = ['last_review_date' => 'date', 'next_review_date' => 'date'];

    public const STATUSES = ['compliant', 'partially_compliant', 'non_compliant', 'under_review', 'not_applicable'];

    protected static function booted(): void
    {
        static::created(fn (self $c) => LegalAudit::record('compliance_created', $c->document_id, null, ['item' => $c->item_code]));
        static::updated(function (self $c) {
            $changes = $c->getChanges();
            unset($changes['updated_at']);
            if ($changes !== []) {
                LegalAudit::record('compliance_updated', $c->document_id, null, ['item' => $c->item_code], array_intersect_key($c->getOriginal(), $changes), $changes);
            }
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function document()
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }

    public function actions()
    {
        return $this->hasMany(LegalCorrectiveAction::class, 'source_id')->where('source_type', 'compliance');
    }
}
