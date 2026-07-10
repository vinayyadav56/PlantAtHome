<?php

namespace App\Modules\Configuration\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Product as CatalogProduct;
use App\Modules\Configuration\Application\ConfigurationService;
use App\Modules\Configuration\Http\Requests\ValidateSelectionRequest;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-facing resolution: what to render on a product page, and validating a
 * selection before add-to-cart. Public (no auth) — a nursery/city are passed as
 * query/body params.
 */
final class ConfigResolutionController extends ApiController
{
    public function __construct(private readonly ConfigurationService $config)
    {
    }

    /** GET /api/v1/config/products/{product}/configuration?variant=&city=&nursery= */
    public function resolve(Request $request, CatalogProduct $product): JsonResponse
    {
        $variant = (string) $request->query('variant', '');
        if ($variant === '') {
            return $this->fail('VARIANT_REQUIRED', 'A variant is required to resolve configuration.', 422, 'variant');
        }

        $resolved = $this->config->resolveForProduct(
            $product->uuid,
            $variant,
            $request->query('city'),
            $request->query('nursery'),
        );

        return $this->ok($resolved);
    }

    /** POST /api/v1/config/products/{product}/validate-selection */
    public function validate(ValidateSelectionRequest $request, CatalogProduct $product): JsonResponse
    {
        $result = $this->config->validateSelection(
            (string) $request->input('variant'),
            (array) $request->input('selection', []),
            $request->input('city'),
            $request->input('nursery'),
        );

        // A failed validation is a normal 200 result (not an error) — the client
        // renders the violations. Only malformed input is a 4xx.
        return $this->ok($result->toArray());
    }
}
