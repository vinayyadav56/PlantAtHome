<?php

namespace App\Modules\Sales\Domain;

/** All Sales lifecycle statuses (Section 8 & 10), grouped by entity. */
final class Status
{
    // Checkout session state machine
    public const CHECKOUT_INITIATED       = 'initiated';
    public const CHECKOUT_VALIDATED        = 'validated';
    public const CHECKOUT_RESERVED         = 'reserved';
    public const CHECKOUT_PAYMENT_PENDING  = 'payment_pending';
    public const CHECKOUT_PAID             = 'paid';
    public const CHECKOUT_ORDERS_CREATED   = 'orders_created';
    public const CHECKOUT_DONE             = 'done';
    public const CHECKOUT_FAILED           = 'failed';

    // Payment intent
    public const PAY_CREATED             = 'created';
    public const PAY_PROCESSING          = 'processing';
    public const PAY_SUCCEEDED           = 'succeeded';
    public const PAY_FAILED              = 'failed';
    public const PAY_REFUNDED            = 'refunded';
    public const PAY_PARTIALLY_REFUNDED  = 'partially_refunded';

    // Sub-order lifecycle (the fulfillment unit)
    public const SUB_PLACED            = 'placed';
    public const SUB_CONFIRMED         = 'confirmed';
    public const SUB_PACKED            = 'packed';
    public const SUB_HANDED_TO_COURIER = 'handed_to_courier';
    public const SUB_OUT_FOR_DELIVERY  = 'out_for_delivery';
    public const SUB_DELIVERED         = 'delivered';
    public const SUB_CANCELLED         = 'cancelled';
    public const SUB_REJECTED          = 'rejected';
    public const SUB_RETURNED          = 'returned';
    public const SUB_REFUNDED          = 'refunded';

    // Parent-order rollup (derived, never set directly)
    public const ORDER_PENDING              = 'pending';
    public const ORDER_PROCESSING           = 'processing';
    public const ORDER_PARTIALLY_FULFILLED  = 'partially_fulfilled';
    public const ORDER_COMPLETED            = 'completed';
    public const ORDER_CANCELLED            = 'cancelled';
    public const ORDER_REFUNDED             = 'refunded';
}
