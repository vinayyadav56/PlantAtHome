<?php

namespace Marvel\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\VendorLedgerEntry;

/**
 * D4 cutover: each vendor's balance at the cutover date is entered ONCE as an OPENING_BALANCE
 * journal (DR Opening Balance Equity / CR Vendor Payables[shop]; negative = vendor owes us,
 * sides swapped) plus a settle-eligible sub-ledger row, flagged REQUIRES_RECONCILIATION until an
 * admin confirms it against the legacy `balances` figure. History is never re-posted.
 */
class OpeningBalanceService
{
    public function __construct(private readonly AccountingConfig $config = new AccountingConfig(), private readonly JournalService $journal = new JournalService())
    {
    }

    /** Legacy figures to prefill from, with any opening entry already posted. */
    public function prefill(): array
    {
        $legacy = Schema::hasTable('balances') ? DB::table('balances')->whereNotNull('shop_id')->get(['shop_id', 'current_balance', 'total_earnings', 'withdrawn_amount'])->keyBy('shop_id') : collect();
        $posted = JournalEntry::where('source_type', 'OPENING_BALANCE')->get()->keyBy(fn ($e) => (int) $e->source_id);
        $shops = array_unique(array_merge(array_keys($legacy->all()), array_keys($posted->all())));
        $names = Schema::hasTable('shops') ? DB::table('shops')->whereIn('id', $shops ?: [0])->pluck('name', 'id') : collect();
        $out = [];
        foreach ($shops as $sid) {
            $e = $posted[$sid] ?? null;
            $out[] = [
                'shop_id' => (int) $sid, 'shop_name' => $names[$sid] ?? null,
                'legacy_balance' => MoneyBridge::toMoney((string) ($legacy[$sid]->current_balance ?? 0))->toDecimal(),
                'opening_amount' => $e ? ($e->metadata['amount'] ?? null) : null,
                'journal' => $e?->entry_number, 'confirmed' => $e ? !$e->requires_reconciliation : false, 'as_of' => $e?->entry_date?->toDateString(),
            ];
        }
        return $out;
    }

    /** Post (idempotent per shop). $amount positive = owed to the vendor. */
    public function post(int $shopId, string $amount, ?string $asOf = null, ?string $actor = null, ?string $note = null): JournalEntry
    {
        $money = MoneyBridge::toMoney($amount);
        $key = 'OPENING_BALANCE:shop:' . $shopId;
        if ($existing = JournalEntry::where('source_key', $key)->first()) {
            return $existing;
        }
        if ($money->isZero()) {
            throw new \InvalidArgumentException('Opening balance must be non-zero.');
        }
        $date = $asOf ?: ($this->config->cutoverDate() ?: Carbon::today()->toDateString());
        $abs = $money->isNegative() ? MoneyBridge::toMoney(-1 * $money->amountMinor() / 100) : $money;
        $c = $this->config;
        $lines = $money->isNegative()
            ? [['account' => $c->accountCode('vendor_payables'), 'debit' => $abs, 'shop_id' => $shopId], ['account' => $c->accountCode('opening_balance_equity'), 'credit' => $abs, 'shop_id' => $shopId]]
            : [['account' => $c->accountCode('opening_balance_equity'), 'debit' => $abs, 'shop_id' => $shopId], ['account' => $c->accountCode('vendor_payables'), 'credit' => $abs, 'shop_id' => $shopId]];
        return DB::transaction(function () use ($lines, $key, $shopId, $money, $date, $actor, $note) {
            $je = $this->journal->postLines($lines, [
                'source_type' => 'OPENING_BALANCE', 'source_id' => $shopId, 'source_key' => $key, 'entry_date' => $date,
                'reference_type' => 'shop', 'reference_id' => $shopId, 'description' => 'Opening vendor balance for shop #' . $shopId . ($note ? ' — ' . $note : ''),
                'requires_reconciliation' => true, 'metadata' => ['amount' => $money->toDecimal(), 'note' => $note], 'actor' => $actor,
            ]);
            if (Schema::hasTable('vendor_ledger_entries')) {
                try {
                    VendorLedgerEntry::create([
                        'shop_id' => $shopId, 'order_id' => null, 'entry_type' => 'opening_balance', 'amount' => $money->toDecimal(),
                        'journal_entry_id' => $je->id, 'idempotency_key' => '0:0:opening_balance:' . $shopId, 'source' => 'cutover',
                        'status' => 'pending', 'available_at' => Carbon::parse($date), 'earned_at' => Carbon::parse($date),
                        'note' => 'Opening balance at cutover' . ($note ? ': ' . $note : ''),
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    if (!str_contains($e->getMessage(), 'UNIQUE') && ($e->errorInfo[0] ?? '') !== '23000') {
                        throw $e;
                    }
                }
            }
            AccountingAuditLog::record('opening_balance', $shopId, 'posted', null, ['amount' => $money->toDecimal(), 'journal' => $je->entry_number], $note, $key, $actor);
            return $je;
        });
    }

    /** Admin confirms the figure against the legacy balance → clears the reconciliation flag. */
    public function confirm(int $shopId, ?string $actor = null, ?string $note = null): JournalEntry
    {
        $je = JournalEntry::where('source_key', 'OPENING_BALANCE:shop:' . $shopId)->firstOrFail();
        if ($je->requires_reconciliation) {
            $je->requires_reconciliation = false;
            $je->metadata = array_merge((array) $je->metadata, ['confirmed_by' => $actor, 'confirmed_at' => Carbon::now()->toDateTimeString(), 'confirm_note' => $note]);
            $je->save();
            AccountingAuditLog::record('opening_balance', $shopId, 'confirmed', ['requires_reconciliation' => true], ['requires_reconciliation' => false], $note, $je->source_key, $actor);
        }
        return $je;
    }
}
