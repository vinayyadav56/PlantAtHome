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

    public function storeRefund($request)
    {
        $user = $request->user();
        $scope = (string) ($request->input('scope') ?: 'full');
        $accounting = \Marvel\Services\Accounting\AccountingPostingService::enabled();
        if ($scope !== 'full' && !$accounting) {
            throw new MarvelException(SOMETHING_WENT_WRONG, 'Partial and item refunds require the accounting module.');
        }
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
        return $this->createSliced($order, $data, $scope, (array) $request->input('items', []), $request->input('requested_amount'), $staff ? $request->input('method') : null);
    }

    /**
     * Create a refund whose money is computed SERVER-SIDE from the order's immutable snapshot
     * (spec §26): full = what the customer paid; items = the chosen lines × qty; partial = an
     * amount allocated across lines. Writes refund_items for item refunds. Never trusts a
     * client amount. Also used by the returns flow.
     */
    public function createSliced(Order $order, array $data, string $scope = 'full', array $items = [], $requestedAmount = null, ?string $method = null)
    {
        $accounting = \Marvel\Services\Accounting\AccountingPostingService::enabled();
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
