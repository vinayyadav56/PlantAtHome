<?php

namespace Tests\Unit\DeliveryOptimizer\Fakes;

use Marvel\Services\DeliveryOptimizer\Contracts\CandidateProviderInterface;
use Marvel\Services\DeliveryOptimizer\Dto\Candidate;
use Marvel\Services\DeliveryOptimizer\Dto\CartItem;
use Marvel\Services\DeliveryOptimizer\Dto\UserLocation;

/** Returns pre-seeded candidates per item key; records warm() calls so the N+1 fix is testable. */
final class FakeCandidateProvider implements CandidateProviderInterface
{
    public int $warmCalls = 0;

    /** @param array<string, Candidate[]> $byItem itemKey => candidates */
    public function __construct(private array $byItem)
    {
    }

    public function warm(array $cartItems, UserLocation $loc): void
    {
        $this->warmCalls++;
    }

    public function candidates(CartItem $item, UserLocation $loc, int $k = 5): array
    {
        return array_slice($this->byItem[$item->key()] ?? [], 0, max(1, $k));
    }
}
