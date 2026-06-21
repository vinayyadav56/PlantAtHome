<?php

namespace Marvel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Marvel\Services\ServiceAvailabilityService;

/**
 * Operations Control Center — platform-level kill-switch guard for transactional
 * routes (checkout / order creation / assignment). Returns 503 when the platform
 * is stopped or (on order routes) when "Stop All Orders" is engaged. Per-vertical
 * / per-city gating happens inside the checkout/order/assignment services.
 *
 * Usage: `->middleware('service.available')` or `service.available:orders`.
 * FAIL OPEN on any error — never block commerce because of a resolver fault.
 */
class CheckServiceAvailability
{
    public function __construct(private ServiceAvailabilityService $availability)
    {
    }

    public function handle(Request $request, Closure $next, string $scope = 'orders')
    {
        // Only gate writes (creation/checkout); reads (e.g. viewing an existing
        // order) stay available even during an emergency stop.
        if ($request->isMethod('get')) {
            return $next($request);
        }
        try {
            $flags = $this->availability->platformFlags();

            if (!empty($flags['stop_platform'])) {
                return $this->unavailable($flags['message'] ?? 'The platform is temporarily unavailable.');
            }
            if ($scope === 'orders' && !empty($flags['stop_orders'])) {
                return $this->unavailable($flags['message'] ?? 'Orders are temporarily paused. Please try again shortly.');
            }
            if ($scope === 'deliveries' && !empty($flags['stop_deliveries'])) {
                return $this->unavailable($flags['message'] ?? 'Deliveries are temporarily paused.');
            }
        } catch (\Throwable $e) {
            // fail open
        }

        return $next($request);
    }

    private function unavailable(string $message)
    {
        return response()->json([
            'message'     => $message,
            'serviceable' => false,
            'reason'      => 'service_unavailable',
        ], 503);
    }
}
