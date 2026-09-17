<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Marvel\Database\Models\Type;
use Marvel\Http\Controllers\TypeController;
use Marvel\Services\ServiceAvailabilityService;
use Tests\TestCase;

/**
 * Regression: "switch fertilizers off everywhere" left it in the nav for
 * SIGNED-IN customers.
 *
 * TypeController::index applied the Operations Control Center vertical filter
 * only when the response was publicly cacheable, and isPublicCacheable() is
 * just `empty($request->bearerToken())`. The storefront sends a bearer token
 * for a logged-in shopper, so they took the uncached branch — which returned
 * the raw, unfiltered list. Guests were filtered correctly, which is why it
 * looked like it worked.
 */
final class TypesVerticalFilterTest extends TestCase
{
    /** Availability map with `plants` on and `fertilizers` off, no DB. */
    private function controller(): TypeController
    {
        $this->app->bind(ServiceAvailabilityService::class, fn () => new class extends ServiceAvailabilityService {
            public function __construct()
            {
            }

            public function map(): array
            {
                return [
                    'all_verticals' => ['plants', 'fertilizers'],
                    'global'        => ['fertilizers' => ['is_active' => false, 'status' => 'disabled', 'message' => null]],
                    'platform'      => ['stop_platform' => false, 'stop_orders' => false, 'stop_deliveries' => false, 'maintenance' => false, 'message' => null],
                    'cities'        => [],
                    'matrix'        => [],
                ];
            }
        });

        $controller = $this->app->make(TypeController::class);
        $controller->repository = new class {
            public function where($column, $value)
            {
                return $this;
            }

            public function get(): EloquentCollection
            {
                return new EloquentCollection([
                    new Type(['slug' => 'plants']),
                    new Type(['slug' => 'fertilizers']),
                ]);
            }
        };

        return $controller;
    }

    private function slugs($resource): array
    {
        return $resource->collection->map(fn ($r) => $r->resource->slug)->all();
    }

    public function test_a_signed_in_shopper_does_not_see_a_disabled_vertical(): void
    {
        $request = Request::create('/types', 'GET');
        // Exactly what the storefront sends once a customer logs in.
        $request->headers->set('Authorization', 'Bearer a-customer-token');

        $this->assertSame(['plants'], $this->slugs($this->controller()->index($request)));
    }

    public function test_a_guest_does_not_see_a_disabled_vertical(): void
    {
        $out = $this->controller()->index(Request::create('/types', 'GET'));
        // Resource wrapping is disabled app-wide, so this is a bare array.
        $data = json_decode($out->getContent(), true);
        $rows = $data['data'] ?? $data;

        $this->assertSame(['plants'], array_column($rows, 'slug'));
    }
}
