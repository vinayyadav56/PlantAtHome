<?php

namespace Marvel\Services\Tracking;

/** The three buckets every visitor, session and event falls into. See config/tracking.php. */
final class TrafficType
{
    public const HUMAN = 'human';
    public const BOT = 'bot';
    public const UNKNOWN = 'unknown';

    public const ALL = [self::HUMAN, self::BOT, self::UNKNOWN];

    public static function valid(?string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
