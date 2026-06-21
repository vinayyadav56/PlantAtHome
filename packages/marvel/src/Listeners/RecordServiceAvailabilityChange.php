<?php

namespace Marvel\Listeners;

use Carbon\Carbon;
use Marvel\Database\Models\ServiceAvailabilityLog;
use Marvel\Events\ServiceAvailabilityChanged;
use Marvel\Services\ServiceAvailabilityService;

/**
 * Writes the immutable audit row and busts the availability cache. Runs
 * synchronously (sync queue) so the new state is live before the request returns.
 */
class RecordServiceAvailabilityChange
{
    public function __construct(private ServiceAvailabilityService $availability)
    {
    }

    public function handle(ServiceAvailabilityChanged $event): void
    {
        ServiceAvailabilityLog::create([
            'entity_type' => $event->entityType,
            'entity_id'   => $event->entityId,
            'old_value'   => $event->oldValue,
            'new_value'   => $event->newValue,
            'changed_by'  => $event->changedBy,
            'reason'      => $event->reason,
            'ip'          => $event->ip,
            'created_at'  => Carbon::now(),
        ]);

        $this->availability->bust();
    }
}
