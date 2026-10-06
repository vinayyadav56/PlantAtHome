<?php

namespace Marvel\Services\Tracking;

/**
 * User-Agent → human | bot | unknown. Pure, no I/O; the tables live in
 * config/tracking.php so adding a crawler is a config edit, not a code change.
 *
 * Conservative by design: a mainstream browser is human unless an automation
 * token is present, and anything we cannot place is "unknown" — never "bot".
 * Nothing here is used to BLOCK; it only labels. Analytics classification and
 * security are separate concerns.
 */
class BotDetectionService
{
    /** @var array<string, array{0: ?string, 1: string}> compiled once per process */
    private array $named = [];
    private string $generic;
    private string $browser;

    public function __construct()
    {
        foreach ((array) config('tracking.bots', []) as $pattern => $meta) {
            $this->named['/(?:' . $pattern . ')/i'] = [$meta[0] ?? null, (string) ($meta[1] ?? 'other')];
        }
        $this->generic = (string) config('tracking.generic_bot_regex', '/\bbot\b/i');
        $this->browser = (string) config('tracking.browser_regex', '/^Mozilla\/5\.0/');
    }

    /**
     * @return array{type: string, name: ?string, bot_type: ?string}
     */
    public function classify(?string $ua): array
    {
        $ua = trim((string) $ua);
        if (strlen($ua) < 10) {
            return $this->result(TrafficType::UNKNOWN);
        }

        foreach ($this->named as $regex => [$name, $type]) {
            if (preg_match($regex, $ua, $m)) {
                return $this->result(TrafficType::BOT, $name ?? $m[0], $type); // the token as the vendor spells it
            }
        }

        if (preg_match($this->generic, $ua, $m)) {
            return $this->result(TrafficType::BOT, $this->tidy($m[1] ?? $m[0]), 'other');
        }

        // A link in the UA ("+http://…/bot.html") is how polite crawlers identify
        // themselves even when they borrow a browser-shaped UA string.
        if (preg_match($this->browser, $ua) && !str_contains($ua, '+http')) {
            return $this->result(TrafficType::HUMAN);
        }

        return $this->result(TrafficType::UNKNOWN);
    }

    public function isBot(?string $ua): bool
    {
        return $this->classify($ua)['type'] === TrafficType::BOT;
    }

    private function result(string $type, ?string $name = null, ?string $botType = null): array
    {
        return ['type' => $type, 'name' => $name !== null ? mb_substr($name, 0, 64) : null, 'bot_type' => $botType];
    }

    /** "python-requests" → "Python-Requests", "bot" → "Bot": a label, not an identifier. */
    private function tidy(string $token): string
    {
        $token = trim($token, " /\\");
        return $token === '' ? 'Other' : ucwords(strtolower($token), '-');
    }
}
