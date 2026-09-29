<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Http\Request;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderWalletPoint;
use Marvel\Facades\Payment;
use Marvel\Traits\PaymentTrait;
use Tests\TestCase;

/**
 * What the gateway is asked to charge, when the customer has spent wallet points.
 *
 * Reported from a real production order (2026092890205948): "even after applying wallet
 * currency payment needs to be done as full payment". createPaymentIntent subtracted
 * `$order->wallet->amount`, but Order defines the relation as `wallet_point()` — there is
 * no `wallet()` — so the expression was always `paid_total - intval(null)`, i.e. the FULL
 * total, and the wallet was debited on top of it.
 *
 * Nothing about that is visible at the call site, which is why it needs a test: the bug is
 * a name that silently resolves to null, and `intval(null)` is a perfectly valid 0.
 */
final class WalletPayableAmountTest extends TestCase
{
    /** The amount handed to the PSP for an order, with the Payment facade faked. */
    private function intentAmountFor(Order $order): float
    {
        $captured = null;

        // swap(), not shouldReceive(): shouldReceive resolves the REAL facade root first, and
        // building it runs ShopServiceProvider's gateway selection, which reads
        // Settings::getData()->options and dies with no settings row in a unit test.
        $gateway = \Mockery::mock();
        $gateway->shouldReceive('getIntent')
            ->once()
            ->andReturnUsing(function (array $intent) use (&$captured) {
                $captured = $intent;
                return ['payment_id' => 'rzp_test_order', 'amount' => $intent['amount']];
            });
        Payment::swap($gateway);

        $caller = new class {
            use PaymentTrait;
        };
        $request = new Request();
        $request->setUserResolver(fn () => null);

        $caller->createPaymentIntent($order, $request, 'RAZORPAY');

        return (float) $captured['amount'];
    }

    /**
     * Built in memory, not persisted: the defect is a RELATION NAME that resolves to null,
     * so what matters is the name this code reads — `wallet_point`, as Order declares it.
     * setRelation uses that same name, so a rename on either side fails the test.
     */
    private function order(float $paidTotal, ?float $walletCredit = null): Order
    {
        $order = new Order();
        $order->id = 1;
        $order->tracking_number = 'W12345678';
        $order->paid_total = $paidTotal;

        $order->setRelation(
            'wallet_point',
            $walletCredit === null ? null : tap(new OrderWalletPoint(), fn ($w) => $w->amount = $walletCredit)
        );

        return $order;
    }

    public function test_wallet_credit_is_subtracted_from_what_the_gateway_charges(): void
    {
        // 500 owed, 200 already covered by wallet ⇒ the customer must be charged 300.
        $this->assertSame(300.00, $this->intentAmountFor($this->order(500.00, 200.00)));
    }

    public function test_wallet_credit_with_paise_is_not_truncated(): void
    {
        // currencyToWalletRatio is 3 on production, so wallet credit is routinely a
        // non-integer: 100 points = ₹33.33. intval() silently billed the customer the
        // 33 paise difference.
        $this->assertSame(466.67, $this->intentAmountFor($this->order(500.00, 33.33)));
    }

    public function test_an_order_with_no_wallet_credit_is_charged_in_full(): void
    {
        $this->assertSame(500.00, $this->intentAmountFor($this->order(500.00)));
    }

    public function test_wallet_credit_never_produces_a_negative_charge(): void
    {
        // A full-wallet order should not reach here at all, but a stale/over-large
        // ledger row must never ask the PSP for a negative amount.
        $this->assertSame(0.0, $this->intentAmountFor($this->order(100.00, 250.00)));
    }
}
