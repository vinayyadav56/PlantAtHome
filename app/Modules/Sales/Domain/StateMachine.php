<?php

namespace App\Modules\Sales\Domain;

use App\Shared\Application\DomainActionException;

/**
 * The legal-transition tables (Section 10) as DATA. Illegal transitions throw a
 * 409 — the set of legal edges lives here, not scattered across if-statements.
 */
final class StateMachine
{
    /** @var array<string, array<string, string[]>> entity => from => [allowed to,…] */
    private const EDGES = [
        'sub_order' => [
            Status::SUB_PLACED            => [Status::SUB_CONFIRMED, Status::SUB_CANCELLED, Status::SUB_REJECTED],
            Status::SUB_CONFIRMED         => [Status::SUB_PACKED, Status::SUB_CANCELLED],
            Status::SUB_PACKED            => [Status::SUB_HANDED_TO_COURIER, Status::SUB_CANCELLED],
            Status::SUB_HANDED_TO_COURIER => [Status::SUB_OUT_FOR_DELIVERY],
            Status::SUB_OUT_FOR_DELIVERY  => [Status::SUB_DELIVERED],
            Status::SUB_DELIVERED         => [Status::SUB_RETURNED],
            Status::SUB_CANCELLED         => [Status::SUB_REFUNDED],
            Status::SUB_REJECTED          => [Status::SUB_REFUNDED],
            Status::SUB_RETURNED          => [Status::SUB_REFUNDED],
        ],
        'payment' => [
            Status::PAY_CREATED    => [Status::PAY_PROCESSING, Status::PAY_FAILED],
            Status::PAY_PROCESSING => [Status::PAY_SUCCEEDED, Status::PAY_FAILED],
            Status::PAY_SUCCEEDED  => [Status::PAY_REFUNDED, Status::PAY_PARTIALLY_REFUNDED],
        ],
        'checkout' => [
            Status::CHECKOUT_INITIATED      => [Status::CHECKOUT_VALIDATED, Status::CHECKOUT_FAILED],
            Status::CHECKOUT_VALIDATED       => [Status::CHECKOUT_RESERVED, Status::CHECKOUT_FAILED],
            Status::CHECKOUT_RESERVED        => [Status::CHECKOUT_PAYMENT_PENDING, Status::CHECKOUT_FAILED],
            Status::CHECKOUT_PAYMENT_PENDING => [Status::CHECKOUT_PAID, Status::CHECKOUT_FAILED],
            Status::CHECKOUT_PAID            => [Status::CHECKOUT_ORDERS_CREATED, Status::CHECKOUT_FAILED],
            Status::CHECKOUT_ORDERS_CREATED  => [Status::CHECKOUT_DONE],
        ],
    ];

    public static function canTransition(string $entity, string $from, string $to): bool
    {
        return in_array($to, self::EDGES[$entity][$from] ?? [], true);
    }

    /** @throws DomainActionException 409 on an illegal transition */
    public static function assert(string $entity, string $from, string $to): void
    {
        if (! self::canTransition($entity, $from, $to)) {
            throw DomainActionException::conflict(
                "Illegal {$entity} transition: {$from} → {$to}.",
                'ILLEGAL_TRANSITION',
            );
        }
    }
}
