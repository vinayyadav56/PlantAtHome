<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Http\Controllers\ProductController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * GET /api/products/{slug} never returned a product's GST configuration, while the admin
 * edit form prefills from that payload and submits EVERY field it registered. So opening
 * and saving any product wrote back an empty hsn_code, a null tax_rate_id and an unticked
 * tax_verified — silently erasing the CA's work on every edit.
 *
 * The four fields are attached AFTER the resource and only for catalogue staff: the same
 * payload is the public PDP (cached 300s), which needs none of them and should not carry
 * an internal compliance flag.
 *
 * No database: the method takes the product and the request, and staff detection resolves
 * the user off the request. Keeps the check to the branch that actually broke.
 */
final class ProductTaxConfigPayloadTest extends TestCase
{
    private const FIELDS = ['hsn_code', 'tax_rate_id', 'tax_inclusive', 'tax_verified'];

    private function attach(array $data, object $product, Request $request): array
    {
        $controller = app(ProductController::class);
        $m = new ReflectionMethod($controller, 'attachTaxConfig');
        $m->setAccessible(true);

        return $m->invoke($controller, $data, $product, $request);
    }

    private function product(): object
    {
        return (object) [
            'id'            => 7,
            'hsn_code'      => '0602',
            'tax_rate_id'   => 3,
            'tax_inclusive' => true,
            'tax_verified'  => true,
        ];
    }

    /** @param string[] $permissions */
    private function requestAs(?array $permissions): Request
    {
        $request = Request::create('/products/areca-palm', 'GET');
        if ($permissions === null) {
            return $request; // anonymous shopper
        }
        $user = new class ($permissions) {
            public function __construct(private array $permissions) {}

            public function hasPermissionTo($permission): bool
            {
                return in_array((string) $permission, $this->permissions, true);
            }
        };
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_staff_receive_the_tax_configuration(): void
    {
        $data = $this->attach(['id' => 7], $this->product(), $this->requestAs([Permission::SUPER_ADMIN]));

        $this->assertSame('0602', $data['hsn_code']);
        $this->assertSame(3, $data['tax_rate_id']);
        $this->assertTrue($data['tax_inclusive']);
        $this->assertTrue($data['tax_verified']);
    }

    public function test_it_writes_into_the_wrapped_payload_when_the_resource_wraps(): void
    {
        $data = $this->attach(['data' => ['id' => 7]], $this->product(), $this->requestAs([Permission::STAFF]));

        $this->assertSame('0602', $data['data']['hsn_code']);
        $this->assertArrayNotHasKey('hsn_code', $data, 'must not also land at the root');
    }

    public function test_the_public_pdp_never_carries_it(): void
    {
        foreach ([null, [Permission::CUSTOMER]] as $permissions) {
            $data = $this->attach(['id' => 7], $this->product(), $this->requestAs($permissions));
            foreach (self::FIELDS as $field) {
                $this->assertArrayNotHasKey($field, $data);
            }
        }
    }

    public function test_an_unconfigured_product_reports_nulls_rather_than_dropping_the_keys(): void
    {
        // The keys must still be PRESENT — the form has to know the value is empty,
        // which is the whole reason a missing key was destructive.
        $data = $this->attach(['id' => 7], (object) ['id' => 7], $this->requestAs([Permission::SUPER_ADMIN]));

        foreach (self::FIELDS as $field) {
            $this->assertArrayHasKey($field, $data);
            $this->assertNull($data[$field]);
        }
    }
}
