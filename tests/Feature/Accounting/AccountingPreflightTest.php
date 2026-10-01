<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;

/**
 * The cutover pre-flight. It changes nothing, so the properties worth pinning are that it RUNS
 * against a real schema, and that it fails for the one reason that genuinely stops a cutover:
 * an account role with no row behind it.
 *
 * That matters because accountCode() throws for an unmapped role, and with strict mode on
 * (its default) that throw rolls back the business change — one missing account stops order
 * completion, not just the bookkeeping.
 */
class AccountingPreflightTest extends OrdersTestCase
{
    public function test_it_passes_against_a_complete_chart_of_accounts(): void
    {
        $this->artisan('plantathome:accounting-preflight')->assertExitCode(0);
    }

    public function test_a_missing_account_is_a_blocker_not_a_warning(): void
    {
        // 2050 = customer_refund_payable, the credit side of every refund posting.
        DB::table('acc_accounts')->where('code', '2050')->delete();

        $this->artisan('plantathome:accounting-preflight')
            ->expectsOutputToContain('customer_refund_payable')
            ->assertExitCode(1);
    }

    public function test_it_reports_orders_that_cannot_take_a_gateway_refund(): void
    {
        $this->s68Order();   // prepaid razorpay order with no gateway_payment_id

        $this->artisan('plantathome:accounting-preflight')
            ->expectsOutputToContain('backfill-gateway-payment-id')
            ->assertExitCode(0);   // a warning, never a blocker
    }
}
