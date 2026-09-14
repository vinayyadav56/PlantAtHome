<?php

namespace Marvel\Services\Accounting;

use App\Shared\Domain\ValueObject\Money;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Accounting\Account;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\Accounting\AccountingPeriod;
use Marvel\Database\Models\Accounting\AccountingSequence;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\Accounting\JournalLine;
use Marvel\Services\Accounting\Exceptions\AccountingException;
use Marvel\Services\Accounting\Exceptions\ClosedPeriodException;
use Marvel\Services\Accounting\Exceptions\ImmutableJournalException;
use Marvel\Services\Accounting\Exceptions\UnbalancedJournalException;

/**
 * The journal: draft → post (balanced, open period, numbered) → reverse. Amounts are
 * summed in integer paise; an entry that does not balance to the paisa is refused.
 * postLines() is the idempotent entry point every financial event goes through: the
 * UNIQUE source_key makes a replayed event a no-op that returns the existing entry.
 *
 * A line is ['account' => code|id|Account, 'debit' => x | 'credit' => y, + optional dims:
 * shop_id, customer_id, order_id, order_item_id, settlement_id, vendor_payment_id,
 * refund_id, shipment_id, tax_kind, hsn_code, tax_rate, description, metadata].
 * Meta: source_type (required), source_id, source_key, entry_date, reference_type,
 * reference_id, description, metadata, actor, requires_reconciliation.
 */
class JournalService
{
    private const DIMS = ['shop_id', 'customer_id', 'order_id', 'order_item_id', 'settlement_id', 'vendor_payment_id', 'refund_id', 'shipment_id', 'tax_kind', 'hsn_code', 'tax_rate', 'description', 'metadata'];

    /** Draft an entry with its lines (unposted). Validates shape + balance; does NOT number it. */
    public function draft(array $lines, array $meta): JournalEntry
    {
        [$rows, $debit, $credit] = $this->normalize($lines);
        $this->assertBalanced($debit, $credit);
        if (empty($meta['source_type'])) {
            throw new AccountingException('source_type is required.');
        }
        $date = isset($meta['entry_date']) ? Carbon::parse($meta['entry_date']) : Carbon::today();

        return DB::transaction(function () use ($rows, $debit, $credit, $meta, $date) {
            $entry = JournalEntry::create([
                'entry_date'              => $date->toDateString(),
                'status'                  => JournalEntry::DRAFT,
                'source_type'             => $meta['source_type'],
                'source_id'               => isset($meta['source_id']) ? (string) $meta['source_id'] : null,
                'source_key'              => $meta['source_key'] ?? ($meta['source_type'] . ':' . ($meta['source_id'] ?? uniqid('', true))),
                'reference_type'          => $meta['reference_type'] ?? null,
                'reference_id'            => $meta['reference_id'] ?? null,
                'description'             => isset($meta['description']) ? mb_substr($meta['description'], 0, 500) : null,
                'currency'                => 'INR',
                'total_debit'             => MoneyBridge::decimal($debit),
                'total_credit'            => MoneyBridge::decimal($credit),
                'reverses_entry_id'       => $meta['reverses_entry_id'] ?? null,
                'requires_reconciliation' => (bool) ($meta['requires_reconciliation'] ?? false),
                'metadata'                => $meta['metadata'] ?? null,
                'created_by'              => $this->actor($meta),
            ]);
            $n = 0;
            foreach ($rows as $r) {
                $r['journal_entry_id'] = $entry->id;
                $r['line_no'] = ++$n;
                $r['entry_date'] = $date->toDateString();
                JournalLine::create($r);
            }
            return $entry;
        });
    }

    /** Post a draft: re-verify balance from the persisted lines, check the period, number it. */
    public function post(JournalEntry $entry, ?string $actor = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $actor) {
            $e = JournalEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($e->status !== JournalEntry::DRAFT) {
                throw new ImmutableJournalException('Journal entry #' . $e->id . ' is already ' . $e->status . '.');
            }
            $debit = MoneyBridge::sum($e->lines()->pluck('debit'));
            $credit = MoneyBridge::sum($e->lines()->pluck('credit'));
            $this->assertBalanced($debit, $credit);

            $period = AccountingPeriod::forDate(Carbon::parse($e->entry_date));
            if ($period->isClosed()) {
                $redirect = (bool) ($e->metadata['redirect_closed_period'] ?? ($e->source_type !== 'MANUAL'));
                if ($redirect) {
                    AccountingPeriod::forDate(Carbon::today()); // the current month exists (open) even if nothing posted into it yet
                }
                $open = $redirect ? AccountingPeriod::where('status', 'open')->where('period_start', '>', $period->period_start)->orderBy('period_start')->first() : null;
                if (!$open) {
                    throw new ClosedPeriodException('Accounting period ' . $period->period_start->toDateString() . ' is closed; post a reversal/adjustment into an open period instead.');
                }
                // Late event into a closed month (spec §46): keep the books closed, post into the next open
                // period, remember the original date and flag it for the reconciliation screen.
                $original = $e->entry_date->toDateString();
                $newDate = max($open->period_start->toDateString(), min(Carbon::today()->toDateString(), $open->period_end->toDateString()));
                $e->entry_date = $newDate;
                $e->metadata = array_merge((array) $e->metadata, ['original_date' => $original, 'redirected_from_period' => $period->period_start->toDateString()]);
                $e->requires_reconciliation = true;
                $e->lines()->update(['entry_date' => $newDate]);
                $period = $open;
            }
            $year = Carbon::parse($e->entry_date)->format('Y');
            $e->entry_number = sprintf('JE-%s-%06d', $year, AccountingSequence::next('journal:' . $year));
            $e->period_id = $period->id;
            $e->status = JournalEntry::POSTED;
            $e->posted_by = $actor ?? $e->created_by ?? 'system';
            $e->posted_at = Carbon::now();
            $e->total_debit = MoneyBridge::decimal($debit);
            $e->total_credit = MoneyBridge::decimal($credit);
            $e->save();

            AccountingAuditLog::record('journal_entry', $e->id, 'posted', null, [
                'entry_number' => $e->entry_number, 'source_key' => $e->source_key,
                'total_debit' => $e->total_debit, 'total_credit' => $e->total_credit,
            ], null, $e->source_key, $e->posted_by);
            return $e;
        });
    }

    /**
     * Draft + post in one transaction, IDEMPOTENT on meta.source_key: a second call with the
     * same key returns the already-posted entry (the UNIQUE index is the arbiter under races).
     */
    public function postLines(array $lines, array $meta): JournalEntry
    {
        $key = $meta['source_key'] ?? null;
        if ($key && ($existing = JournalEntry::where('source_key', $key)->first())) {
            Log::info('accounting: duplicate financial event ignored', ['source_key' => $key, 'entry' => $existing->id]);
            return $existing;
        }
        try {
            return DB::transaction(fn () => $this->post($this->draft($lines, $meta), $this->actor($meta)));
        } catch (QueryException $e) {
            if ($key && $this->isUniqueViolation($e)) {
                Log::info('accounting: duplicate financial event lost the race', ['source_key' => $key]);
                return JournalEntry::where('source_key', $key)->firstOrFail();
            }
            throw $e;
        }
    }

    /** Reverse a posted entry: mirrored lines (same dimensions), original marked REVERSED. */
    public function reverse(JournalEntry $original, string $reason, ?string $actor = null, ?string $entryDate = null): JournalEntry
    {
        return DB::transaction(function () use ($original, $reason, $actor, $entryDate) {
            $o = JournalEntry::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($o->status !== JournalEntry::POSTED) {
                throw new ImmutableJournalException('Only POSTED entries can be reversed (#' . $o->id . ' is ' . $o->status . ').');
            }
            $lines = [];
            foreach ($o->lines as $l) {
                $row = ['account' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit];
                foreach (self::DIMS as $d) {
                    if ($l->{$d} !== null) {
                        $row[$d] = $l->{$d};
                    }
                }
                $row['description'] = 'Reversal: ' . ($l->description ?? '');
                $lines[] = $row;
            }
            $rev = $this->postLines($lines, [
                'source_type'       => 'REVERSAL',
                'source_id'         => $o->id,
                'source_key'        => 'REVERSAL:' . $o->id,
                'entry_date'        => $entryDate ?? Carbon::today()->toDateString(),
                'reference_type'    => $o->reference_type,
                'reference_id'      => $o->reference_id,
                'description'       => 'Reversal of ' . $o->entry_number . ': ' . $reason,
                'reverses_entry_id' => $o->id,
                'metadata'          => ['reason' => $reason, 'reversed_entry' => $o->entry_number],
                'actor'             => $actor,
            ]);
            $o->status = JournalEntry::REVERSED;
            $o->reversed_by_entry_id = $rev->id;
            $o->save();
            AccountingAuditLog::record('journal_entry', $o->id, 'reversed', ['status' => 'posted'], ['status' => 'reversed', 'reversed_by' => $rev->entry_number], $reason, $o->source_key, $actor);
            return $rev;
        });
    }

    /** Correction = reversal of the original + a fresh corrected entry. Returns [reversal, corrected]. */
    public function correct(JournalEntry $original, array $newLines, array $meta, string $reason, ?string $actor = null): array
    {
        return DB::transaction(function () use ($original, $newLines, $meta, $reason, $actor) {
            $rev = $this->reverse($original, $reason, $actor);
            $meta['source_type'] = $meta['source_type'] ?? 'CORRECTION';
            $meta['source_key'] = $meta['source_key'] ?? ('CORRECTION:' . $original->id);
            $meta['metadata'] = array_merge((array) ($meta['metadata'] ?? []), ['corrects' => $original->entry_number, 'reason' => $reason]);
            $meta['actor'] = $actor;
            return [$rev, $this->postLines($newLines, $meta)];
        });
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return array{0: array<int, array>, 1: Money, 2: Money} */
    private function normalize(array $lines): array
    {
        if (count($lines) < 2) {
            throw new UnbalancedJournalException('A journal entry needs at least two lines.');
        }
        $accounts = $this->resolveAccounts(array_column($lines, 'account'));
        $rows = [];
        $debit = MoneyBridge::zero();
        $credit = MoneyBridge::zero();
        foreach ($lines as $i => $l) {
            $acct = $accounts[$this->accountKey($l['account'] ?? null)] ?? null;
            if (!$acct) {
                throw new AccountingException('Unknown account on line ' . ($i + 1) . '.');
            }
            if (!$acct->is_active) {
                throw new AccountingException('Account ' . $acct->code . ' is inactive.');
            }
            $d = MoneyBridge::toMoney($l['debit'] ?? 0);
            $c = MoneyBridge::toMoney($l['credit'] ?? 0);
            if ($d->isNegative() || $c->isNegative()) {
                throw new UnbalancedJournalException('Negative amounts are not allowed; swap the side instead (line ' . ($i + 1) . ').');
            }
            if (($d->isZero() && $c->isZero()) || (!$d->isZero() && !$c->isZero())) {
                throw new UnbalancedJournalException('Each line must carry exactly one of debit or credit (line ' . ($i + 1) . ').');
            }
            $debit = $debit->add($d);
            $credit = $credit->add($c);
            $row = ['account_id' => $acct->id, 'debit' => MoneyBridge::decimal($d), 'credit' => MoneyBridge::decimal($c)];
            foreach (self::DIMS as $dim) {
                if (array_key_exists($dim, $l) && $l[$dim] !== null) {
                    $row[$dim] = $dim === 'description' ? mb_substr((string) $l[$dim], 0, 500) : $l[$dim];
                }
            }
            $rows[] = $row;
        }
        return [$rows, $debit, $credit];
    }

    private function assertBalanced(Money $debit, Money $credit): void
    {
        if (!$debit->equals($credit) || $debit->isZero()) {
            throw new UnbalancedJournalException(sprintf('Journal does not balance: debits %s vs credits %s.', $debit->toDecimal(), $credit->toDecimal()));
        }
    }

    /** @return array<string, Account> keyed by accountKey() */
    private function resolveAccounts(array $refs): array
    {
        $codes = [];
        $ids = [];
        $out = [];
        foreach ($refs as $r) {
            if ($r instanceof Account) {
                $out[$this->accountKey($r)] = $r;
            } elseif (is_int($r) || (is_string($r) && ctype_digit($r) && strlen($r) > 4)) {
                $ids[] = (int) $r;
            } elseif ($r !== null) {
                $codes[] = (string) $r;
            }
        }
        if ($ids) {
            foreach (Account::whereIn('id', $ids)->get() as $a) {
                $out['id:' . $a->id] = $a;
            }
        }
        if ($codes) {
            foreach (Account::whereIn('code', $codes)->get() as $a) {
                $out['code:' . $a->code] = $a;
            }
        }
        return $out;
    }

    private function accountKey(mixed $r): string
    {
        if ($r instanceof Account) {
            return 'id:' . $r->id;
        }
        if (is_int($r) || (is_string($r) && ctype_digit($r) && strlen($r) > 4)) {
            return 'id:' . (int) $r;
        }
        return 'code:' . (string) $r;
    }

    private function actor(array $meta): string
    {
        if (!empty($meta['actor'])) {
            return (string) $meta['actor'];
        }
        try {
            $u = auth()->user();
            return $u?->id ? (string) $u->id : 'system';
        } catch (\Throwable $e) {
            return 'system';
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 alone also covers FK / NOT NULL violations — require the duplicate-key signal
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            || str_contains(strtolower($e->getMessage()), 'unique')
            || str_contains(strtolower($e->getMessage()), 'duplicate');
    }
}
