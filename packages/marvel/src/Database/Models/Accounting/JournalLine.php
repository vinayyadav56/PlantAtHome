<?php

namespace Marvel\Database\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Services\Accounting\Exceptions\ImmutableJournalException;

/** One side of a journal entry. Exactly one of debit/credit is non-zero. Immutable once posted. */
class JournalLine extends Model
{
    protected $table = 'acc_journal_lines';
    public $guarded = [];
    protected $casts = [
        'entry_date' => 'date:Y-m-d',
        'debit'      => 'decimal:2',
        'credit'     => 'decimal:2',
        'tax_rate'   => 'decimal:4',
        'metadata'   => 'array',
    ];

    protected static function booted(): void
    {
        $guard = function (self $line, string $verb) {
            $status = $line->entry()->value('status');
            if (in_array($status, [JournalEntry::POSTED, JournalEntry::REVERSED], true)) {
                throw new ImmutableJournalException('Cannot ' . $verb . ' a line of a ' . $status . ' journal entry (#' . $line->journal_entry_id . ').');
            }
        };
        static::updating(fn (self $l) => $guard($l, 'update'));
        static::deleting(fn (self $l) => $guard($l, 'delete'));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
