<?php

namespace App\Modules\Nursery\Http\Concerns;

use App\Modules\Nursery\Infrastructure\Models\Nursery;
use App\Shared\Application\DomainActionException;

/**
 * Admin-facing {nursery} path params accept a uuid OR a slug — the admin's
 * vendor drill-down navigates by slug. Resolution is explicit (uuid first,
 * slug fallback) rather than a global Route::bind, because Identity's
 * /nursery/{nursery}/dashboard uses the same param name as a raw scope string.
 */
trait ResolvesNursery
{
    private function resolveNursery(string $identifier): Nursery
    {
        $nursery = Nursery::byUuid($identifier)->first()
            ?? Nursery::query()->where('slug', $identifier)->first();

        if (! $nursery) {
            throw DomainActionException::notFound('Nursery not found.', 'NURSERY_NOT_FOUND');
        }

        return $nursery;
    }
}
