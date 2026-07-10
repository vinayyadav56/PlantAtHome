<?php

namespace App\Modules\Inventory\Domain;

/** Lifecycle of an inventory reservation. */
final class ReservationStatus
{
    public const ACTIVE    = 'active';    // holding stock, TTL running
    public const COMMITTED = 'committed'; // paid → deducted from on-hand
    public const RELEASED  = 'released';  // checkout abandoned/failed
    public const EXPIRED   = 'expired';   // TTL lapsed, stock returned
}
