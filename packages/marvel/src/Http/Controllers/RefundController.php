<?php

namespace Marvel\Http\Controllers;

use App\Events\QuestionAnswered;
use App\Events\RefundApproved;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\Wallet;
use Marvel\Database\Repositories\RefundRepository;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\PaymentStatus;
use Marvel\Enums\Permission;
use Marvel\Enums\RefundStatus;
use Marvel\Events\OrderCancelled;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\RefundRequest;
use Marvel\Http\Resources\GetSingleRefundResource;
use Marvel\Http\Resources\RefundResource;
use Marvel\Listeners\ProductInventoryRestore;
use Marvel\Services\VendorLedgerService;
use Marvel\Traits\OrderManagementTrait;
use Marvel\Traits\WalletsTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RefundController extends CoreController
{
    use WalletsTrait;
    use OrderManagementTrait;

    public $repository;

    public function __construct(RefundRepository $repository)
    {
        $this->repository = $repository;
    }


    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Type[]
     */
    public function index(Request $request)
    {
        $limit = $request->limit;
        $refunds =  $this->fetchRefunds($request)->paginate($limit);
        $data = RefundResource::collection($refunds)->response()->getData(true);
        return formatAPIResourcePaginate($data);
    }

    public function fetchRefunds(Request $request)
    {
        try {
            $language = $request->language ?? DEFAULT_LANGUAGE;
            $user = $request->user();
            if (!$user) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }

            $orderQuery = $this->repository->whereHas('order', function ($q) use ($language) {
                $q->where('language', $language);
            });

            switch ($user) {
                case $user->hasPermissionTo(Permission::SUPER_ADMIN):
                    if ((!isset($request->shop_id) || $request->shop_id === 'undefined')) {
                        return $orderQuery->where('id', '!=', null)->where('shop_id', '=', null);
                    }
                    return $orderQuery->where('shop_id', '=', $request->shop_id);
                    break;

                case $this->repository->hasPermission($user, $request->shop_id):
                    return $orderQuery->where('shop_id', '=', $request->shop_id);
                    break;

                case $user->hasPermissionTo(Permission::CUSTOMER):
                    return $orderQuery->where('customer_id', $user->id)->where('shop_id', null);
                    break;

                default:
                    return $orderQuery->where('customer_id', $user->id)->where('shop_id', null);
                    break;
            }
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param RefundRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(RefundRequest $request)
    {
        try {
            if (!$request->user()) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
            return $this->repository->storeRefund($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show(Request $request, $id)
    {
        try {
            $refund = $this->repository->with(['shop', 'order', 'customer', 'refund_policy','refund_reason'])->findOrFail($id);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }
        // SECURITY: this route is only role-gated (can:customer) and had NO per-refund ownership
        // check, so any customer could read any refund (and the buyer's PII) by guessing the id.
        $user = $request->user();
        $authorized = $user && (
            $refund->customer_id === $user->id
            || $user->hasPermissionTo(Permission::SUPER_ADMIN)
            || (isset($refund->shop_id) && $this->repository->hasPermission($user, $refund->shop_id))
        );
        if (!$authorized) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        return new GetSingleRefundResource($refund);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request  $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            return $this->updateRefund($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function updateRefund(Request $request)
    {
        $user = $request->user();

        if (!$this->repository->hasPermission($user)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }

        try {
            $refund = $this->repository->with(['shop', 'order', 'customer'])->findOrFail($request->id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }

        if ($refund->status == RefundStatus::APPROVED) {
            throw new HttpException(400, ALREADY_REFUNDED);
        }

        // Non-approval transitions (REJECTED / PROCESSING / …) carry no money side effects.
        if ($request->status != RefundStatus::APPROVED) {
            $this->repository->updateRefund($request, $refund);
            return $refund;
        }

        // ── APPROVAL: a money mutation. Make it atomic, serialized and idempotent. ──
        // Everything (refund + order status flips, vendor/ledger/DP reversal, inventory
        // restore, wallet credit) happens in ONE transaction; a row-locked compare-and-swap
        // on the refund status makes a concurrent/retried approval a no-op instead of a
        // double credit/debit. Reversals mirror EXACTLY what completion credited.
        return DB::transaction(function () use ($request, $refund) {
            $locked = Refund::whereKey($refund->id)
                ->where('status', '!=', RefundStatus::APPROVED)
                ->lockForUpdate()
                ->first();
            if (!$locked) {
                // Another approval already won the race.
                throw new HttpException(400, ALREADY_REFUNDED);
            }

            $order = Order::with('children')->findOrFail($locked->order_id);

            // Snapshot the children's PRE-refund status + the order's paid figure BEFORE the
            // repository flips them to REFUNDED — we reverse only what was actually credited.
            $prevChildStatus  = [];
            foreach ($order->children as $child) {
                $prevChildStatus[$child->id] = $child->order_status;
            }
            $wasPaidOnline = $order->payment_status === PaymentStatus::SUCCESS;
            $refundable    = (float) $order->paid_total; // live paid_total already nets prior cancellations

            // Flip refund (+ child refund rows) and order/children to REFUNDED.
            $this->repository->updateRefund($request, $locked);

            foreach ($order->children as $childOrder) {
                $wasCompleted = ($prevChildStatus[$childOrder->id] ?? null) === OrderStatus::COMPLETED;

                if ($wasCompleted) {
                    // Reverse the vendor earning on the SAME basis it was credited
                    // (commission-net of ->total, atomic) AND reverse the vendor ledger sale.
                    // updateBalanceShop is null-safe + idempotent-via-flag for the ledger.
                    $this->updateBalanceShop($childOrder, 'deduct');
                    // Reverse the delivery-partner commission that completion credited.
                    $this->manageDeliveryPartnerBalance($childOrder, OrderStatus::REFUNDED, OrderStatus::COMPLETED);
                } else {
                    // Never completed → never credited. Defensively reverse any ledger sale
                    // (idempotent + a no-op when the marketplace ledger flag is off).
                    try {
                        (new VendorLedgerService())->reverseSale($childOrder);
                    } catch (\Throwable $e) {
                        // ledger is non-authoritative — never break the refund
                    }
                }

                // Restore stock for every suborder that wasn't already cancelled (cancelled
                // suborders had their stock restored at cancellation — don't double-restore).
                // Invoke the restore listener directly so stock is returned WITHOUT emitting an
                // "order cancelled" customer notification (wrong semantics + SMS cost on a refund).
                if (($prevChildStatus[$childOrder->id] ?? null) !== OrderStatus::CANCELLED) {
                    try {
                        (new ProductInventoryRestore())->handle(new OrderCancelled($childOrder));
                    } catch (\Throwable $e) {
                        // inventory restore must never break the refund money path
                    }
                }
            }

            // Refund the customer to wallet — ONLY what they actually paid online, and only
            // when the order was prepaid+captured. COD/unpaid refunds are settled off-platform;
            // crediting wallet points there would mint value the customer never paid.
            if ($wasPaidOnline && $refundable > 0) {
                $walletPoints = $this->currencyToWalletPoints($refundable);
                $wallet = Wallet::firstOrCreate(['customer_id' => $locked->customer_id]);
                $wallet->total_points     = (float) $wallet->total_points + $walletPoints;
                $wallet->available_points = (float) $wallet->available_points + $walletPoints;
                $wallet->save();
            }

            event(new RefundApproved($locked));

            return $locked;
        });
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            return $this->deleteRefund($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }

    public function deleteRefund(Request $request)
    {
        try {
            $refund = $this->repository->findOrFail($request->id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
        if ($this->repository->hasPermission($request->user())) {
            $refund->delete();
            return $refund;
        } else {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }
}
