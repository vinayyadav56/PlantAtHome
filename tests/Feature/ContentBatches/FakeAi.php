<?php

namespace Tests\Feature\ContentBatches;

/**
 * Stand-in for the `ai` binding. Records every call so a test can assert that a
 * row which needs no generation never triggered a (paid) request, and lets a
 * test script per-call outcomes — including a throw, to drive the retry path.
 */
class FakeAi
{
    /** @var array<int, object> every request handed to generateProductContent */
    public array $calls = [];

    /** @var array<int, mixed> queued responses; an \Throwable is thrown instead */
    public array $queue = [];

    public mixed $default = ['description' => '<p>Generated copy.</p>', 'category_ids' => []];

    public function generateProductContent(object $request): mixed
    {
        $this->calls[] = $request;

        $next = array_shift($this->queue) ?? $this->default;
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
