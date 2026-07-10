<?php

namespace App\Modules\Promotions\Http\Controllers;

use App\Modules\Promotions\Application\PromotionService;
use App\Modules\Promotions\Infrastructure\Models\Coupon;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PromotionController extends ApiController
{
    public function __construct(private readonly PromotionService $promotions)
    {
    }

    /** GET /api/v1/promotions/coupons (admin) */
    public function index(): JsonResponse
    {
        return $this->ok(Coupon::orderByDesc('id')->get()->map(fn (Coupon $c) => $this->present($c))->all());
    }

    /** POST /api/v1/promotions/coupons (admin) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'               => ['required', 'string', 'max:64', 'unique:promo_coupons,code'],
            'type'               => ['required', Rule::in(['fixed', 'percentage'])],
            'value'              => ['required', 'numeric', 'min:0'],
            'min_subtotal'       => ['nullable', 'numeric', 'min:0'],
            'max_discount'       => ['nullable', 'numeric', 'min:0'],
            'usage_limit'        => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'valid_from'         => ['nullable', 'date'],
            'valid_to'           => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        return $this->created($this->present($this->promotions->createCoupon($data)));
    }

    /** POST /api/v1/promotions/validate — preview a coupon's discount. */
    public function validateCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'          => ['required', 'string', 'max:64'],
            'subtotal_minor' => ['required', 'integer', 'min:0'],
        ]);

        return $this->ok($this->promotions->evaluate($data['code'], (int) $data['subtotal_minor'], $request->user()?->uuid));
    }

    private function present(Coupon $c): array
    {
        return [
            'uuid' => $c->uuid, 'code' => $c->code, 'type' => $c->type, 'value' => $c->value,
            'min_subtotal' => $c->min_subtotal, 'usage_limit' => $c->usage_limit,
            'used_count' => $c->used_count, 'is_active' => (bool) $c->is_active,
        ];
    }
}
