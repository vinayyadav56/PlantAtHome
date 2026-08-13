<?php


namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Class RoleType
 * @package App\Enums
 */
final class OrderStatus extends Enum
{
    public const PENDING              = 'order-pending';
    public const PROCESSING           = 'order-processing';
    public const COMPLETED            = 'order-completed';
    public const CANCELLED            = 'order-cancelled';
    public const REFUNDED             = 'order-refunded';
    public const FAILED               = 'order-failed';
    public const AT_LOCAL_FACILITY    = 'order-at-local-facility';
    public const OUT_FOR_DELIVERY     = 'order-out-for-delivery';
    public const DEFAULT_ORDER_STATUS = 'order-pending';

    /**
     * The forward-only lifecycle ladder, lowest rung first: status => rank. The ONE
     * definition shared by the transition guard + parent rollup (OrderManagementTrait)
     * and the courier status seam (CourierService::applyNormalizedStatus).
     *
     * CANCELLED/REFUNDED/FAILED are deliberately absent: they interrupt the ladder
     * rather than sit on it, so they have no rank and can never be "advanced" to.
     *
     * A method and not a const because BenSampo's Enum reflects EVERY class constant
     * into getValues(), which the orders migration feeds straight to $table->enum().
     */
    public static function ranks(): array
    {
        return [
            self::PENDING           => 0,
            self::PROCESSING        => 1,
            self::AT_LOCAL_FACILITY => 2,
            self::OUT_FOR_DELIVERY  => 3,
            self::COMPLETED         => 4,
        ];
    }
}