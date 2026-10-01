<?php


namespace Marvel\Database\Repositories;

use Exception;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Refund;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\PaymentStatus;
use Marvel\Enums\Permission;
use Marvel\Enums\RefundStatus;
use Marvel\Exceptions\MarvelException;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class RefundRepository extends BaseRepository
{
    protected $fieldSearchable = [
        'title',
        'order_id',
        'description',
        'refund_policy_id',
        'refund_policy.slug',
        'refund_reason.slug',
    ];

    protected $dataArray = [
        'order_id',
        'images',
        'title',
        'description',
        'refund_policy_id',
        'refund_reason_id'
    ];
    /**
     * Configure the Model
     **/
    public function model()
    {
        return Refund::class;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
        }
    }

    /**
     * Migrations run in the BACKGROUND of a deploy on Railway/EC2, so the column can lag the
     * code. Guard the write rather than let refund creation fail on an unknown column.
     */
    protected function refundsSupportIdempotencyKey(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = \Illuminate\Support\Facades\Schema::hasColumn('refunds', 'idempotency_key');
            } catch (\Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    public function storeRefund($request)
    {
        $user = $request->user();
        $scope = (string) ($request->input('scope') ?: 'full');
        $accounting = \Marvel\Services\Accounting\AccountingPostingService::enabled();
        // NB: the "partial/item needs accounting" guard is NOT here any more — it lives in
        // createSliced(), which is the chokepoint BOTH callers reach. See the note there.
        // One open refund at a time; and with accounting on, several settled refunds may exist
        // as long as they never exceed what the customer paid (checked in slices()).
        $open = $this->where('order_id', $request->order_id)->whereNull('shop_id')->whereIn('status', [RefundStatus::PENDING, RefundStatus::PROCESSING])->exists();
        if ($open || (!$accounting && $this->where('order_id', $request->order_id)->exists())) {
            throw new MarvelException(ORDER_ALREADY_HAS_REFUND_REQUEST);
        }
        try {
            $order = Order::findOrFail($request->order_id);
            if ($order->parent !== null) {
                throw new MarvelException(REFUND_ONLY_ALLOWED_FOR_MAIN_ORDER);
            }
        } catch (Exception $th) {
            throw new MarvelException(NOT_FOUND);
        }
        // Allow the owning customer OR a super-admin; reject everyone else. (The previous
        // `!== || hasPermission` form wrongly blocked super-admins who own the order and
        // read as an inverted check.)
        if (!($user->id === $order->customer_id || $user->hasPermissionTo(Permission::SUPER_ADMIN))) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
        $data = $request->only($this->dataArray);
        $data['customer_id'] = $order->customer_id;
        // The payout method is an admin decision at approval; a customer's hint is ignored.
        $staff = $user->hasPermissionTo(Permission::SUPER_ADMIN) || $user->hasPermissionTo(Permission::STAFF);
        // Is this refund ALLOWED? Separate question from what it is worth, and deliberately
        // answered before any money is computed. Default-permissive: with no configured policy
        // this is a no-op, so existing behaviour is unchanged until rules are filled in.
        $items = [];
        foreach ((array) $request->input('items', []) as $line) {
            if (!empty($line['order_item_id'])) {
                $items[(int) $line['order_item_id']] = (int) ($line['quantity'] ?? 1);
            }
        }
        \Marvel\Services\Accounting\RefundPolicyService::make()->assertAllowed(
            $order,
            $items,
            $request->input('requested_amount') !== null ? (float) $request->input('requested_amount') : null
        );

        return $this->createSliced($order, $data, $scope, (array) $request->input('items', []), $request->input('requested_amount'), $staff ? $request->input('method') : null, $request->input('idempotency_key'));
    }

    /**
     * Create a refund whose money is computed SERVER-SIDE from the order's immutable snapshot
     * (spec §26): full = what the customer paid; items = the chosen lines × qty; partial = an
     * amount allocated across lines. Writes refund_items for item refunds. Never trusts a
     * client amount. Also used by the returns flow.
     */
    public function createSliced(Order $order, array $data, string $scope = 'full', array $items = [], $requestedAmount = null, ?string $method = null, ?string $idempotencyKey = null)
    {
        // Replay protection for the CREATE. Deliberately caller-supplied rather than derived
        // from the contents: two identical ₹500 partial refunds on one order are legitimate
        // (PRD §21 — multiple partials against one payment), so a content hash would block
        // real work. Same shape as orders (OrderRepository::storeOrder).
        //
        // The column is UNIQUE and has existed since the P10 migration without anything ever
        // writing to it; until now a retried POST after a settled refund created a second row.
        if ($idempotencyKey !== null && $idempotencyKey !== '' && $this->refundsSupportIdempotencyKey()) {
            $existing = $this->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
            $data['idempotency_key'] = $idempotencyKey;
        }

        $accounting = \Marvel\Services\Accounting\AccountingPostingService::enabled();

        // Without the accounting module there is no slicer, so `amount` below stays at the FULL
        // paid_total and `scope` stays at the DB default 'full' — approving it then refunds the
        // whole order. That is survivable for a deliberate full refund and catastrophic for a
        // sliced one: returning one ₹500 plant from a ₹5,000 order would refund ₹5,000.
        //
        // This guard used to live in storeRefund(), the caller. ReturnService::refund() calls
        // THIS method directly and so walked straight past it. The guard belongs at the
        // chokepoint, which is here.
        if ($scope !== 'full' && !$accounting) {
            // Message first, constant second: MarvelException renders `reason` only into the
            // GraphQL extensions block, so over REST — which is what the admin uses — the
            // second argument is invisible. The explanation has to BE the message.
            throw new MarvelException('Partial and item refunds require the accounting module.', SOMETHING_WENT_WRONG);
        }

        // Snapshot what the customer actually PAID (paid_total = subtotal + tax + delivery −
        // discount), not the bare product subtotal — otherwise refunds under-pay by tax+delivery.
        $data['amount'] = $order->paid_total;
        $slices = null;
        if ($accounting) {
            $slices = \Marvel\Services\Accounting\RefundService::make()->slices($order, $scope, $items, $requestedAmount !== null ? number_format((float) $requestedAmount, 2, '.', '') : null);
            $data['amount'] = (float) $slices['amount']->toDecimal();
            $data['scope'] = $scope;
            $data['requested_amount'] = $slices['amount']->toDecimal();
            $data['method'] = $method;
        }
        $refund = $this->create($data);
        if ($slices) {
            foreach ($slices['lines'] as $itemId => $l) {
                \Illuminate\Support\Facades\DB::table('refund_items')->insert([
                    'refund_id' => $refund->id, 'order_item_id' => $itemId, 'quantity' => $l['quantity'], 'amount' => $l['amount']->toDecimal(),
                    'taxable_value' => $l['taxable']->toDecimal(), 'tax_amount' => $l['tax']->toDecimal(), 'cgst_amount' => $l['cgst']->toDecimal(),
                    'sgst_amount' => $l['sgst']->toDecimal(), 'igst_amount' => $l['igst']->toDecimal(), 'discount_amount' => $l['discount']->toDecimal(),
                    'vendor_share' => $l['vendor_share']->toDecimal(), 'shop_id' => $l['shop_id'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        if ($scope === 'full') {
            $this->createChildOrderRefund($order->children, $data);
        }

        // The refund timeline lives in order_events, NOT a separate refund_events table: this is
        // already the order's audit trail, it is already served by GET orders/{id}/events, and the
        // admin already renders it. A third audit store alongside order_events and acc_audit_log
        // would be one more place to look and one more to keep in step.
        //
        // Emitted here rather than in createChildOrderRefund, which mirrors the refund onto each
        // suborder — a shopper made ONE request and the timeline should say so once.
        \Marvel\Database\Models\OrderEvent::record(
            (int) $order->id,
            'refund.requested',
            ['refund_id' => $refund->id, 'scope' => $scope, 'amount' => (string) $refund->amount],
            'Refund requested'
        );

        return $this->find($refund->id);
    }

    public function createChildOrderRefund($orders, $data)
    {
        try {
            foreach ($orders as  $order) {
                $data['order_id'] = $order->id;
                $data['customer_id'] = $order->customer_id;
                $data['shop_id'] = $order->shop_id;
                $data['amount'] = $order->paid_total; // what was paid for this suborder, not bare subtotal
                $this->create($data);
            }
        } catch (Exception $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function updateRefund($request, $refund)
    {
        if ($refund->shop_id !==  null) {
            throw new MarvelException(WRONG_REFUND);
        }
        $data = $request->only(['status']);
        $refund->update($data);
        $this->changeShopSpecificRefundStatus($refund->order_id, $data);

        // Only a FULL refund makes the order "refunded"; a partial/item refund leaves the order and its
        // recognised revenue standing (the sliced reversal is the whole accounting effect).
        if ($refund['status'] == RefundStatus::APPROVED && (($refund->scope ?? 'full') === 'full')) {
            $orderData['order_status'] = OrderStatus::REFUNDED;
            $orderData['payment_status'] = PaymentStatus::REFUNDED;
            $this->changeOrderStatus($refund->order_id, $orderData);
        }
        return $refund;
    }

    private function changeShopSpecificRefundStatus($order_id, $data)
    {
        $order = Order::with('children')->findOrFail($order_id);

        $childOrderIds = array_map(function ($childOrder) {
            return $childOrder['id'];
        }, $order->children->toArray());

        $this->whereIn('order_id',  $childOrderIds)->update($data);
    }

    private function changeOrderStatus($parentOrderId, array $data)
    {
        $parentOrder = Order::findOrFail($parentOrderId);
        $prev = $parentOrder->order_status;
        $parentOrder->update($data);
        Order::where('parent_id', $parentOrder->id)->update($data);
        // This raw flip bypasses OrderManagementTrait::changeOrderStatus, so the accounting seam is
        // invoked here explicitly. A full refund already reversed everything via RefundService;
        // derecognizeOrder detects that and is a no-op (idempotent).
        \Marvel\Services\Accounting\AccountingPostingService::onOrderStatusChanged($parentOrder, $prev, $data['order_status'] ?? $parentOrder->order_status, 'system:refund');
    }
}
