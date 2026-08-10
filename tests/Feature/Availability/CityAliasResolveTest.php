<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use Marvel\Services\ServiceAvailabilityService;
use Tests\TestCase;

/**
 * Regression: a "New Delhi" (and every Delhi NCT sub-district) delivery address hit the
 * checkout availability gate (assertVerticalsAvailable → resolve) and 503'd with
 * "unavailable in New Delhi", because ServiceAvailabilityService::norm() lowercased WITHOUT
 * the city alias table that the rest of the app uses — so it matched the separate,
 * non-serviceable "New Delhi" sub-district row instead of the serviceable canonical "Delhi".
 *
 * The map's city KEYS are per-row (so a non-serviceable sub-district can't drag the canonical
 * city down via the most-restrictive aggregation); only the resolve LOOKUP is aliased.
 */
final class CityAliasResolveTest extends TestCase
{
    /** A resolver with a fixed availability map (no DB) — the city rows as they are on staging. */
    private function svc(array $cities): ServiceAvailabilityService
    {
        return new class($cities) extends ServiceAvailabilityService {
            public function __construct(private array $fx)
            {
            }

            public function map(): array
            {
                return [
                    'all_verticals' => ['plants'],
                    'global'        => [],
                    'platform'      => ['stop_platform' => false, 'stop_orders' => false, 'stop_deliveries' => false, 'maintenance' => false, 'message' => null],
                    'cities'        => $this->fx,
                    'matrix'        => [],
                ];
            }
        };
    }

    public function test_new_delhi_and_nct_subdistricts_alias_to_serviceable_delhi(): void
    {
        // Real staging state: "Delhi" serviceable; the "New Delhi" sub-district row is NOT.
        $svc = $this->svc([
            'delhi'     => ['ids' => [1], 'accepts' => true,  'status' => 'active'],
            'new delhi' => ['ids' => [2], 'accepts' => false, 'status' => 'active'],
        ]);

        $this->assertTrue($svc->resolve('plants', 'New Delhi')['available'], 'New Delhi must alias to the serviceable Delhi');
        $this->assertTrue($svc->resolve('plants', 'Delhi')['available']);
        $this->assertTrue($svc->resolve('plants', 'South West Delhi')['available'], 'NCT sub-districts alias too');
    }

    public function test_a_genuinely_non_serviceable_city_still_blocks(): void
    {
        $svc = $this->svc([
            'jaipur' => ['ids' => [9], 'accepts' => false, 'status' => 'paused'],
        ]);

        $res = $svc->resolve('plants', 'Jaipur');
        $this->assertFalse($res['available']);
        $this->assertSame('city_paused', $res['reason']);
    }
}
