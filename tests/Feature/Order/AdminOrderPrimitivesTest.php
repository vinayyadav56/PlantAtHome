<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Illuminate\Http\Request;
use Marvel\Database\Repositories\OrderRepository;
use Tests\TestCase;

/**
 * The admin custom-order trust primitives:
 *  - effectiveCustomerId is THE rule for whose order/wallet/idempotency scope
 *    a request acts on (super-admin may name a customer; everyone else acts
 *    as themselves; anonymous ⇒ guest/null).
 * Same stub-request style as the courier/settings suites.
 */
final class AdminOrderPrimitivesTest extends TestCase
{
    private function requestAs(?object $user, array $body = []): Request
    {
        $request = new Request($body);
        $request->setUserResolver(fn () => $user);
        return $request;
    }

    private function superAdmin(int $id = 1): object
    {
        return new class($id) {
            public $id;
            public function __construct($id) { $this->id = $id; }
            public function hasPermissionTo($p): bool { return true; }
        };
    }

    private function staff(int $id = 7): object
    {
        return new class($id) {
            public $id;
            public function __construct($id) { $this->id = $id; }
            public function hasPermissionTo($p): bool { return false; }
        };
    }

    public function test_super_admin_may_name_a_customer(): void
    {
        $req = $this->requestAs($this->superAdmin(1), ['customer_id' => 42]);
        $this->assertSame(42, OrderRepository::effectiveCustomerId($req));
    }

    public function test_super_admin_without_customer_id_acts_as_self(): void
    {
        $req = $this->requestAs($this->superAdmin(1), []);
        $this->assertSame(1, OrderRepository::effectiveCustomerId($req));
    }

    public function test_staff_customer_id_is_ignored(): void
    {
        $req = $this->requestAs($this->staff(7), ['customer_id' => 42]);
        $this->assertSame(7, OrderRepository::effectiveCustomerId($req));
    }

    public function test_anonymous_caller_is_guest_even_with_customer_id(): void
    {
        $req = $this->requestAs(null, ['customer_id' => 42]);
        $this->assertNull(OrderRepository::effectiveCustomerId($req));
    }
}
