<?php

namespace Marvel\Ai;

/** OpenAI image call failed. retryable = 429/5xx/transport (worth another attempt). */
class ImageGenerationException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }
}
