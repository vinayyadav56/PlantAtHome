<?php

namespace Marvel\Database\Models\Accounting;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/** A calendar-month accounting period. Closed periods refuse normal posting (spec §46). */
class AccountingPeriod extends Model
{
    protected $table = 'acc_accounting_periods';
    public $guarded = [];
    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end'   => 'date:Y-m-d',
        'closed_at'    => 'datetime',
    ];

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /** The period containing $date, auto-created OPEN (never auto-closed). */
    public static function forDate(Carbon $date): self
    {
        $start = $date->copy()->startOfMonth()->toDateString();
        return static::firstOrCreate(['period_start' => $start], [
            'period_end' => $date->copy()->endOfMonth()->toDateString(),
            'status'     => 'open',
        ]);
    }
}
