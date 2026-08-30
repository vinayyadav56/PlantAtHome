<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marvel\Database\Models\Legal\Concerns\HasLegalUuid;
use Marvel\Database\Models\User;
use Marvel\Services\Legal\RiskScoring;

class LegalRiskItem extends Model
{
    use HasLegalUuid;
    use SoftDeletes;

    protected $table = 'legal_risk_items';
    protected $guarded = ['id'];
    protected $casts = ['review_date' => 'date'];

    protected static function booted(): void
    {
        // Score and level are derived, never client-supplied.
        static::saving(function (self $risk) {
            $risk->risk_score = RiskScoring::score((int) $risk->probability, (int) $risk->impact);
            $risk->risk_level = RiskScoring::level($risk->risk_score);
        });
        static::created(fn (self $r) => LegalAudit::record('risk_created', null, null, ['risk' => $r->risk_code, 'level' => $r->risk_level]));
        static::updated(function (self $r) {
            $changes = $r->getChanges();
            unset($changes['updated_at']);
            if ($changes !== []) {
                LegalAudit::record('risk_updated', null, null, ['risk' => $r->risk_code], array_intersect_key($r->getOriginal(), $changes), $changes);
            }
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function actions()
    {
        return $this->hasMany(LegalCorrectiveAction::class, 'source_id')->where('source_type', 'risk');
    }
}
