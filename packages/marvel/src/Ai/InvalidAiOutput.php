<?php

namespace Marvel\Ai;

/**
 * The model answered, but the reply can't be used (cut off, or not JSON). It still
 * cost tokens, so it carries the call's usage for the run's cost total.
 */
class InvalidAiOutput extends \UnexpectedValueException
{
    /** @param array{prompt_tokens?: int, completion_tokens?: int} $usage */
    public function __construct(string $message, public array $usage = [])
    {
        parent::__construct($message);
    }
}
