<?php

namespace Marvel\Database\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Row-locked gapless counters (journal numbers, credit notes, invoice register). */
class AccountingSequence extends Model
{
    protected $table = 'acc_sequences';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $guarded = [];

    /** Next value for $key, atomically. Joins the caller's transaction when there is one. */
    public static function next(string $key): int
    {
        $run = function () use ($key): int {
            $row = static::where('key', $key)->lockForUpdate()->first();
            if (!$row) {
                static::query()->insertOrIgnore(['key' => $key, 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $row = static::where('key', $key)->lockForUpdate()->first();
            }
            $n = (int) $row->next_value;
            $row->next_value = $n + 1;
            $row->save();
            return $n;
        };
        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }
}
