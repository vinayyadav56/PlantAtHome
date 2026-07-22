<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\Channels;

/** Outcome of one channel send attempt. Immutable. */
final class ChannelResult
{
    /** @param array<string,mixed> $response */
    private function __construct(
        public readonly bool $ok,
        public readonly string $provider,
        public readonly ?string $messageId,
        public readonly array $response,
        public readonly ?string $error,
        public readonly bool $skipped = false,
    ) {
    }

    /** @param array<string,mixed> $response */
    public static function sent(string $provider, ?string $messageId = null, array $response = []): self
    {
        return new self(true, $provider, $messageId, $response, null);
    }

    /** @param array<string,mixed> $response */
    public static function failed(string $provider, string $error, array $response = []): self
    {
        return new self(false, $provider, null, $response, $error);
    }

    /** Dispatch is disabled (dry-run) — counts as sent, but nothing left the building. */
    public static function skipped(string $provider): self
    {
        return new self(true, $provider, null, ['dry_run' => true], null, true);
    }
}
