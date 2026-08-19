<?php

namespace Marvel\Services\TestDataCleanup;

interface CleanerContract
{
    /** Machine key ('orders', 'vendors', …). */
    public function key(): string;

    /** Human label for the admin screen. */
    public function label(): string;

    /** One line describing exactly what this module removes — shown above the confirm button. */
    public function description(): string;

    /** Live counts for the module card (what exists right now). */
    public function stats(): array;

    /** Resolve the scope into an ordered, id-resolved deletion plan. Deletes nothing. */
    public function plan(array $scope): CleanupPlan;
}
