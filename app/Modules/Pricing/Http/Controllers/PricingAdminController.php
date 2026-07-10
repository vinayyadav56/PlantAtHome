<?php

namespace App\Modules\Pricing\Http\Controllers;

use App\Modules\Pricing\Application\PricingService;
use App\Modules\Pricing\Http\Requests\BasePriceRequest;
use App\Modules\Pricing\Http\Requests\TaxRuleRequest;
use App\Modules\Pricing\Http\Requests\VendorOverrideRequest;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;

/** Admin/vendor pricing inputs: base prices, vendor overrides, tax rules. */
final class PricingAdminController extends ApiController
{
    public function __construct(private readonly PricingService $pricing)
    {
    }

    /** POST /api/v1/pricing/base-prices (admin) */
    public function setBasePrice(BasePriceRequest $request): JsonResponse
    {
        $row = $this->pricing->setBasePrice(
            (string) $request->input('sellable_type'),
            (string) $request->input('sellable_uuid'),
            (float) $request->input('amount'),
            (string) $request->input('currency', 'INR'),
            $request->user()?->uuid,
        );

        return $this->created(['uuid' => $row->uuid, 'amount' => $row->amount, 'currency' => $row->currency]);
    }

    /** POST /api/v1/pricing/vendor-overrides (vendor-own or admin) */
    public function setVendorOverride(VendorOverrideRequest $request): JsonResponse
    {
        $user = $request->user();
        $nurseryId = (string) $request->input('nursery_id');
        if (! $user->isPlatformAdmin() && (string) $user->nursery_id !== $nurseryId) {
            return $this->fail('FORBIDDEN_SCOPE', 'You can only set price overrides for your own nursery.', 403);
        }

        $row = $this->pricing->setVendorOverride(
            $nurseryId,
            (string) $request->input('sellable_type'),
            (string) $request->input('sellable_uuid'),
            (float) $request->input('amount'),
            (string) $request->input('currency', 'INR'),
            $user?->uuid,
        );

        return $this->created(['uuid' => $row->uuid, 'nursery_id' => $row->nursery_id, 'amount' => $row->amount]);
    }

    /** POST /api/v1/pricing/tax-rules (admin) */
    public function setTaxRule(TaxRuleRequest $request): JsonResponse
    {
        $row = $this->pricing->setTaxRule(
            $request->input('category_uuid'),
            $request->input('hsn_code'),
            (float) $request->input('gst_rate'),
            $request->input('name'),
        );

        return $this->created(['uuid' => $row->uuid, 'gst_rate' => $row->gst_rate, 'category_uuid' => $row->category_uuid]);
    }
}
