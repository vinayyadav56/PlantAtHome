<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\DTO;

use App\Modules\Marketing\Domain\ScheduleType;
use Illuminate\Support\Carbon;

/**
 * A campaign's schedule as a value object. Knows how to compute the next fire
 * time so the service/scheduler don't hand-roll cron math.
 */
final class ScheduleConfig
{
    public function __construct(
        public readonly string $type,
        public readonly ?Carbon $scheduledAt,
        public readonly ?string $cron,
        public readonly string $timezone,
    ) {
    }

    /** @param array<string,mixed> $data validated request payload */
    public static function fromArray(array $data, string $defaultTimezone): self
    {
        $scheduledAt = ! empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;

        return new self(
            type: $data['schedule_type'] ?? ScheduleType::NOW,
            scheduledAt: $scheduledAt,
            cron: $data['cron_expression'] ?? null,
            timezone: $data['timezone'] ?? $defaultTimezone,
        );
    }

    /** Next fire time (UTC) strictly after $after, or null if it never recurs. */
    public function nextRunAt(?Carbon $after = null): ?Carbon
    {
        return ScheduleType::nextRunAt($this->type, $this->scheduledAt, $this->cron, $this->timezone, $after);
    }

    public function isRecurring(): bool
    {
        return ScheduleType::isRecurring($this->type);
    }
}
