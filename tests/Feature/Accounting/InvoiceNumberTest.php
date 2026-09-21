<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Services\Tax\InvoiceNumberService;

/**
 * Tax invoice numbering.
 *
 * The invoice used to print "{prefix}-{tracking_number}", which is not a number
 * in any sense a return needs: not sequential, no financial-year context, and
 * issued for orders that never became invoices. A missing number in an invoice
 * series is something a tax officer asks about, so the counter is the same
 * row-locked, gapless one behind journal entries and credit notes.
 *
 * The two properties worth pinning: a number is minted exactly once per order
 * (a replayed webhook must not mint a second), and it is only minted when the
 * order is genuinely payable — an abandoned checkout that burned a number would
 * leave precisely the hole this exists to avoid.
 */
final class InvoiceNumberTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasColumn('orders', 'invoice_number')) {
            Schema::table('orders', function ($t) {
                $t->string('invoice_number', 24)->nullable();
                $t->timestamp('invoice_date')->nullable();
            });
        }
        if (!Schema::hasTable('acc_sequences')) {
            Schema::create('acc_sequences', function ($t) {
                $t->string('key')->primary();
                $t->unsignedBigInteger('next_value')->default(1);
                $t->timestamps();
            });
        }
        DB::table('settings')->delete();
        DB::table('settings')->insert([
            'options'  => json_encode(['tax' => ['invoice_prefix' => 'INV']]),
            'language' => 'en',
        ]);
    }

    private function order(string $paymentStatus, int $id): Order
    {
        return Order::create([
            'id' => $id, 'tracking_number' => 'T' . $id, 'customer_id' => 1,
            'payment_status' => $paymentStatus, 'order_status' => 'order-processing',
            'amount' => 1000, 'paid_total' => 1000, 'total' => 1000,
        ]);
    }

    public function test_a_paid_order_is_numbered_for_the_financial_year(): void
    {
        $order = $this->order('payment-success', 9001);

        $this->assertMatchesRegularExpression('/^INV-\d{4}-000001$/', $order->fresh()->invoice_number);
    }

    public function test_a_cod_order_is_numbered_when_it_is_placed(): void
    {
        $order = $this->order('payment-cash-on-delivery', 9002);

        $this->assertNotNull($order->fresh()->invoice_number);
    }

    public function test_an_unpaid_order_burns_no_number(): void
    {
        $pending = $this->order('payment-pending', 9003);
        $this->assertNull($pending->fresh()->invoice_number);

        // ...and the next real order still takes 000001, so the series has no gap.
        $paid = $this->order('payment-success', 9004);
        $this->assertStringEndsWith('-000001', $paid->fresh()->invoice_number);
    }

    public function test_numbers_are_sequential(): void
    {
        $first = $this->order('payment-success', 9005)->fresh()->invoice_number;
        $second = $this->order('payment-success', 9006)->fresh()->invoice_number;

        $this->assertStringEndsWith('-000001', $first);
        $this->assertStringEndsWith('-000002', $second);
    }

    public function test_paying_later_numbers_the_order_then(): void
    {
        $order = $this->order('payment-pending', 9007);
        $this->assertNull($order->fresh()->invoice_number);

        $order->update(['payment_status' => 'payment-success']);

        $this->assertNotNull($order->fresh()->invoice_number);
    }

    public function test_a_replayed_webhook_does_not_mint_a_second_number(): void
    {
        $order = $this->order('payment-pending', 9008);
        $order->update(['payment_status' => 'payment-success']);
        $minted = $order->fresh()->invoice_number;

        // the gateway sends the same event again
        $order->fresh()->update(['payment_status' => 'payment-success']);
        $order->fresh()->update(['order_status' => 'order-completed']);

        $this->assertSame($minted, $order->fresh()->invoice_number);
    }

    public function test_a_refund_never_takes_the_number_away(): void
    {
        // The supply happened and the document was issued; reversing it is a
        // credit note's job, not an erasure.
        $order = $this->order('payment-success', 9009);
        $minted = $order->fresh()->invoice_number;

        $order->fresh()->update(['payment_status' => 'payment-reversal', 'order_status' => 'order-refunded']);

        $this->assertSame($minted, $order->fresh()->invoice_number);
    }

    public function test_the_financial_year_starts_in_april_not_january(): void
    {
        $this->assertSame('2627', InvoiceNumberService::financialYear(Carbon::parse('2026-09-21')));
        $this->assertSame('2627', InvoiceNumberService::financialYear(Carbon::parse('2027-03-31')));
        $this->assertSame('2728', InvoiceNumberService::financialYear(Carbon::parse('2027-04-01')));
    }

    public function test_the_invoice_date_is_when_it_was_raised(): void
    {
        $order = $this->order('payment-success', 9010);

        $this->assertNotNull($order->fresh()->invoice_date, 'reprinting must not re-date the invoice');
    }
}
