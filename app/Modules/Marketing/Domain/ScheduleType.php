<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain;

use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * How a campaign recurs. Given a schedule type (+ scheduled_at / cron / tz) it
 * computes the next fire time the scheduler polls on. `now` fires immediately
 * and does not recur; `once` fires at scheduled_at once; the rest recur.
 */
final class ScheduleType
{
    public const NOW     = 'now';
    public const ONCE    = 'once';
    public const DAILY   = 'daily';
    public const WEEKLY  = 'weekly';
    public const MONTHLY = 'monthly';
    public const CRON    = 'cron';

    /** @return string[] */
    public static function all(): array
    {
        return [self::NOW, self::ONCE, self::DAILY, self::WEEKLY, self::MONTHLY, self::CRON];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function isRecurring(string $type): bool
    {
        return in_array($type, [self::DAILY, self::WEEKLY, self::MONTHLY, self::CRON], true);
    }

    /**
     * The next moment this campaign should fire, in UTC, or null if it never
     * fires again. `after` anchors recurrence (pass the last run time to advance).
     */
    public static function nextRunAt(
        string $type,
        ?Carbon $scheduledAt,
        ?string $cron,
        string $timezone = 'UTC',
        ?Carbon $after = null,
    ): ?Carbon {
        $tz = self::safeTimezone($timezone);
        $from = ($after ?? Carbon::now())->copy();

        return match ($type) {
            self::NOW  => Carbon::now(),
            self::ONCE => $scheduledAt?->copy(),
            self::DAILY   => self::advanceFromCron('0 9 * * *', $scheduledAt, $tz, $from, self::DAILY),
            self::WEEKLY  => self::advanceFromCron('0 9 * * 1', $scheduledAt, $tz, $from, self::WEEKLY),
            self::MONTHLY => self::advanceFromCron('0 9 1 * *', $scheduledAt, $tz, $from, self::MONTHLY),
            self::CRON    => $cron ? self::advanceFromCron($cron, null, $tz, $from, self::CRON) : null,
            default       => null,
        };
    }

    /**
     * Derive a cron from the operator's chosen time-of-day (scheduled_at) so
     * "daily/weekly/monthly at HH:MM" honours their pick — including the chosen
     * DAY (weekly → day-of-week, monthly → day-of-month), not just the base
     * cron's fixed Monday/1st — then get the next fire strictly after $from, all
     * computed in the campaign timezone and returned UTC.
     */
    private static function advanceFromCron(string $baseCron, ?Carbon $scheduledAt, \DateTimeZone $tz, Carbon $from, string $type): ?Carbon
    {
        $cron = $scheduledAt ? self::mergeSchedule($baseCron, $scheduledAt->copy()->setTimezone($tz), $type) : $baseCron;

        try {
            $expression = new CronExpression($cron);
            $localFrom = $from->copy()->setTimezone($tz);
            $next = $expression->getNextRunDate($localFrom->toDateTime());

            return Carbon::instance($next)->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Merge the operator's chosen minute/hour (+ weekday for weekly, day-of-month
     * for monthly) into the base cron. cron fields: [min hour dom mon dow].
     */
    private static function mergeSchedule(string $baseCron, Carbon $at, string $type): string
    {
        $parts = preg_split('/\s+/', trim($baseCron));
        if (! is_array($parts) || count($parts) < 5) {
            return $baseCron;
        }
        $parts[0] = (string) $at->minute;
        $parts[1] = (string) $at->hour;
        if ($type === self::WEEKLY) {
            $parts[4] = (string) $at->dayOfWeek; // 0=Sun … 6=Sat (cron + Carbon agree)
        }
        if ($type === self::MONTHLY) {
            $parts[2] = (string) $at->day;       // day of month
        }

        return implode(' ', $parts);
    }

    private static function safeTimezone(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }
}
