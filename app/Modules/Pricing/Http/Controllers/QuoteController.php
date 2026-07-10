<?php

namespace App\Modules\Pricing\Http\Controllers;

use App\Modules\Pricing\Application\PricingService;
use App\Modules\Pricing\Http\Requests\QuoteRequest;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;

/**
 * Server-side price quote. Recomputed from identifiers only — the returned total
 * is authoritative and cannot be influenced by any client-posted amount.
 */
final class QuoteController extends ApiController
{
    public function __construct(private readonly PricingService $pricing)
    {
    }

    /** POST /api/v1/pricing/quote */
    public function quote(QuoteRequest $request): JsonResponse
    {
        $req = $request->validated();
        // customer identity comes from the token, never the body (per-customer
        // coupon limits must not be spoofable).
        $req['customer_uuid'] = $request->user()?->uuid;

        return $this->ok($this->pricing->priceLine($req));
    }
}
