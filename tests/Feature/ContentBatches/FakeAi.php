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

    /** @var array<int, object> every request handed to generatePlantContent */
    public array $plantCalls = [];

    /**
     * @var array<int, mixed> scripted plant replies, one per call: a \Throwable
     * is thrown; an array overrides parts of the default (valid) reply —
     * `fields` (merged over the good values), `scientific_name_confidence`, `usage`.
     */
    public array $plantQueue = [];

    /** @var callable|null runs inside generatePlantContent — e.g. an admin edit landing mid-call */
    public $duringPlantCall = null;

    /** Simulated model latency per plant call. */
    public int $latencyMs = 0;

    public array $usage = ['prompt_tokens' => 500, 'completion_tokens' => 200];

    public function generateProductContent(object $request): mixed
    {
        $this->calls[] = $request;

        $next = array_shift($this->queue) ?? $this->default;
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** What a well-behaved model returns — every value passes validation. */
    public static function goodValues(): array
    {
        return [
            'description'       => '<p>' . str_repeat('A lush, easy-going plant that brightens a sunny windowsill and grows happily in a pot. ', 3) . '</p>',
            'seo_title'         => 'Buy Tulsi Plant Online in India',
            'seo_description'   => 'Bring home a Tulsi plant grown in our nursery, delivered potted and ready to thrive on your sunny balcony or windowsill.',
            'hindi_name'        => 'तुलसी',
            'scientific_name'   => 'Ocimum tenuiflorum',
            'sunlight'          => 'Full Sun',
            'water_requirement' => 'Moderate',
        ];
    }

    public function generatePlantContent(object $request): mixed
    {
        $this->plantCalls[] = $request;
        if ($this->duringPlantCall) {
            ($this->duringPlantCall)($request);
        }
        if ($this->latencyMs > 0) {
            usleep($this->latencyMs * 1000);
        }

        $next = array_shift($this->plantQueue) ?? [];
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return [
            'fields'                     => array_merge(
                array_intersect_key(self::goodValues(), array_flip($request->fields)),
                $next['fields'] ?? []
            ),
            'scientific_name_confidence' => array_key_exists('scientific_name_confidence', $next) ? $next['scientific_name_confidence'] : 'high',
            'usage'                      => $next['usage'] ?? $this->usage,
            'model'                      => 'gpt-4o-mini',
        ];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** @return string[][] the fields requested by each plant call, in order */
    public function requestedFields(): array
    {
        return array_map(fn ($r) => $r->fields, $this->plantCalls);
    }
}
