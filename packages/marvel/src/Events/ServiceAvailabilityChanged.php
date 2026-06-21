<?php

namespace Marvel\Events;

/**
 * Operations Control Center — fired on EVERY availability change (global toggle,
 * city×vertical cell, city status, emergency, bulk). Its listener writes the
 * audit row + busts the availability cache, so config / cache / audit never drift.
 */
class ServiceAvailabilityChanged
{
    public function __construct(
        public string $entityType,   // global_vertical | city_vertical | city | platform | bulk
        public ?string $entityId,
        public ?array $oldValue,
        public ?array $newValue,
        public ?string $reason = null,
        public ?int $changedBy = null,
        public ?string $ip = null,
    ) {
    }
}
