<?php


namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Class RoleType
 * @package App\Enums
 */
final class PaymentGatewayType extends Enum
{
    public const STRIPE = 'STRIPE';
    public const CASH_ON_DELIVERY = 'CASH_ON_DELIVERY';
    public const CASH = 'CASH';
    public const FULL_WALLET_PAYMENT = 'FULL_WALLET_PAYMENT';
    public const PAYPAL = 'PAYPAL';
    public const RAZORPAY = 'RAZORPAY';
    public const MOLLIE = 'MOLLIE';
    public const SSLCOMMERZ = 'SSLCOMMERZ';
    public const PAYSTACK = 'PAYSTACK';
    public const XENDIT = 'XENDIT';
    public const IYZICO = 'IYZICO';
    public const BKASH = 'BKASH';
    public const PAYMONGO = 'PAYMONGO';
    public const FLUTTERWAVE = 'FLUTTERWAVE';

    /**
     * Is this gateway "cash collected at the door"?
     *
     * Checkout accepts CASH_ON_DELIVERY, COD and CASH interchangeably and stores whichever the
     * client sent (CheckoutRepository::463), so every consumer has to treat all three alike. The
     * absence of this predicate WAS a bug: CourierService compared with === CASH_ON_DELIVERY, so a
     * 'CASH' order was booked with the courier as PREPAID and the rider was never told to collect —
     * a silent loss of the whole order value.
     *
     * FULL_WALLET_PAYMENT is deliberately excluded: it is prepaid from wallet balance. Some other
     * call sites group it with cash for a different question ("was an online gateway used?"), which
     * is why they keep their own in_array rather than calling this.
     */
    public static function isCashOnDelivery($gateway): bool
    {
        return in_array(strtoupper(trim((string) $gateway)), [
            self::CASH_ON_DELIVERY,
            'COD',
            self::CASH,
        ], true);
    }
}
