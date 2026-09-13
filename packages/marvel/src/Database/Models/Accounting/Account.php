<?php

namespace Marvel\Database\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One chart-of-accounts row. Accounts with journal lines are never deleted — deactivate. */
class Account extends Model
{
    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    protected $table = 'acc_accounts';
    public $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'is_system' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /** Debit-normal accounts grow with debits (assets, expenses, contra-revenue). */
    public function isDebitNormal(): bool
    {
        return $this->normal_side === 'debit';
    }

    public function hasTransactions(): bool
    {
        return $this->lines()->exists();
    }
}
