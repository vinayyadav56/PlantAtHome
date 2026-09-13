<?php

namespace Marvel\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Marvel\Database\Models\Accounting\Account;
use Marvel\Database\Models\Accounting\AccountingPeriod;

/**
 * Seeds the configurable chart of accounts (spec §4 starting set + the accounts the
 * principal-recognition model needs: customer advances, wallet liability, opening
 * balance equity, discounts contra, rounding) and opens the current month's period.
 * Idempotent: upserts by code, never deactivates, never touches balances.
 */
class AccountingSeeder extends Seeder
{
    /** code => [name, type, normal_side, parent_code|null, is_system] */
    public static function chart(): array
    {
        $a = 'asset'; $l = 'liability'; $e = 'equity'; $r = 'revenue'; $x = 'expense';
        return [
            '1000' => ['Assets', $a, 'debit', null, true],
            '1010' => ['Bank', $a, 'debit', '1000', true],
            '1020' => ['Payment Gateway Receivable', $a, 'debit', '1000', true],
            '1030' => ['Customer Receivable', $a, 'debit', '1000', true],
            '1040' => ['Inventory', $a, 'debit', '1000', true],
            '1050' => ['Other Current Assets', $a, 'debit', '1000', true],
            '1060' => ['Input GST Receivable', $a, 'debit', '1000', true],
            '2000' => ['Liabilities', $l, 'credit', null, true],
            '2010' => ['Vendor Payables', $l, 'credit', '2000', true],
            '2020' => ['CGST Payable', $l, 'credit', '2000', true],
            '2030' => ['SGST Payable', $l, 'credit', '2000', true],
            '2040' => ['IGST Payable', $l, 'credit', '2000', true],
            '2050' => ['Customer Refund Payable', $l, 'credit', '2000', true],
            '2060' => ['Other Current Liabilities', $l, 'credit', '2000', true],
            '2070' => ['Customer Advances (Unearned Revenue)', $l, 'credit', '2000', true],
            '2080' => ['Customer Wallet Liability', $l, 'credit', '2000', true],
            '2090' => ['Delivery Partner Payables', $l, 'credit', '2000', true],
            '3000' => ['Equity', $e, 'credit', null, true],
            '3010' => ['Capital', $e, 'credit', '3000', true],
            '3020' => ['Retained Earnings', $e, 'credit', '3000', true],
            '3030' => ['Opening Balance Equity', $e, 'credit', '3000', true],
            '4000' => ['Revenue', $r, 'credit', null, true],
            '4010' => ['Product Sales', $r, 'credit', '4000', true],
            '4015' => ['Discounts Given', $r, 'debit', '4000', true], // contra-revenue
            '4020' => ['Platform Commission', $r, 'credit', '4000', true],
            '4030' => ['Delivery Revenue', $r, 'credit', '4000', true],
            '4040' => ['Other Operating Revenue', $r, 'credit', '4000', true],
            '5000' => ['Cost of Goods / Direct Costs', $x, 'debit', null, true],
            '5010' => ['Cost of Goods Sold', $x, 'debit', '5000', true],
            '5020' => ['Vendor Settlement Cost', $x, 'debit', '5000', true],
            '5030' => ['Delivery Cost', $x, 'debit', '5000', true],
            '5040' => ['Packaging Cost', $x, 'debit', '5000', true],
            '5050' => ['Payment Gateway Charges', $x, 'debit', '5000', true],
            '6000' => ['Operating Expenses', $x, 'debit', null, true],
            '6010' => ['Salaries', $x, 'debit', '6000', false],
            '6020' => ['Marketing', $x, 'debit', '6000', false],
            '6030' => ['Technology', $x, 'debit', '6000', false],
            '6040' => ['Rent', $x, 'debit', '6000', false],
            '6050' => ['Utilities', $x, 'debit', '6000', false],
            '6060' => ['Other Expenses', $x, 'debit', '6000', false],
            '6070' => ['Rounding Differences', $x, 'debit', '6000', true],
        ];
    }

    public function run(): void
    {
        $ids = [];
        foreach (self::chart() as $code => [$name, $type, $side, $parent, $system]) {
            $acct = Account::updateOrCreate(['code' => $code], [
                'name'        => $name,
                'type'        => $type,
                'normal_side' => $side,
                'parent_id'   => $parent ? ($ids[$parent] ?? Account::where('code', $parent)->value('id')) : null,
                'is_system'   => $system,
            ]);
            $ids[$code] = $acct->id;
        }
        AccountingPeriod::forDate(Carbon::today());
    }
}
