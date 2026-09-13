<?php

namespace Marvel\Database\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Marvel\Services\Accounting\Exceptions\ImmutableJournalException;

/**
 * A journal entry: the ONLY way money enters the books. Once POSTED its amounts, dates,
 * source and lines are immutable — corrections are a REVERSAL + a new entry (spec §6, §31,
 * §58). The model enforces that at the ORM boundary; the service enforces balance.
 */
class JournalEntry extends Model
{
    public const DRAFT = 'draft';
    public const POSTED = 'posted';
    public const REVERSED = 'reversed';

    /** Attributes frozen once posted. status/reversed_by/requires_reconciliation may still change. */
    public const FROZEN = [
        'entry_number', 'entry_date', 'period_id', 'source_type', 'source_id', 'source_key',
        'reference_type', 'reference_id', 'currency', 'total_debit', 'total_credit',
        'reverses_entry_id', 'created_by', 'posted_by', 'posted_at',
    ];

    protected $table = 'acc_journal_entries';
    public $guarded = [];
    protected $casts = [
        'entry_date'              => 'date:Y-m-d',
        'posted_at'               => 'datetime',
        'requires_reconciliation' => 'boolean',
        'metadata'                => 'array',
        'total_debit'             => 'decimal:2',
        'total_credit'            => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $e) {
            $was = $e->getOriginal('status');
            if (in_array($was, [self::POSTED, self::REVERSED], true)) {
                $dirty = array_keys($e->getDirty());
                $illegal = array_intersect($dirty, self::FROZEN);
                // A posted entry may only move to REVERSED (never back to draft/posted).
                if (in_array('status', $dirty, true) && !($was === self::POSTED && $e->status === self::REVERSED)) {
                    $illegal[] = 'status';
                }
                if ($illegal) {
                    throw new ImmutableJournalException(
                        'Journal entry #' . $e->id . ' is ' . $was . '; cannot change ' . implode(', ', $illegal) . '. Use a reversal.'
                    );
                }
            }
        });
        static::deleting(function (self $e) {
            if (in_array($e->status, [self::POSTED, self::REVERSED], true)) {
                throw new ImmutableJournalException('Posted journal entries are never deleted (#' . $e->id . ').');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('line_no');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }
}
