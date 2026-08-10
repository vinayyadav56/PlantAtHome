<?php

namespace Marvel\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Dompdf\Options;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Marvel\Database\Models\DownloadToken;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Exports\OrderExport;
use Marvel\Http\Requests\OrderCreateRequest;
use Marvel\Http\Requests\OrderUpdateRequest;
use Marvel\Traits\OrderManagementTrait;
use Marvel\Traits\PaymentStatusManagerWithOrderTrait;
use Marvel\Traits\PaymentTrait;
use Marvel\Traits\TranslationTrait;
use Marvel\Traits\WalletsTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


class OrderController extends CoreController
{
    use WalletsTrait,
        OrderManagementTrait,
        TranslationTrait,
        PaymentStatusManagerWithOrderTrait,
        PaymentTrait;

    public OrderRepository $repository;
    public Settings $settings;

    public function __construct(OrderRepository $repository)
    {
        $this->repository = $repository;
        $this->settings = Settings::first();
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Order[]
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $repository = $this->fetchOrders($request);
        // ?has_rto=1 — only orders with at least one bounced (return-to-origin)
        // shipment. RTO is a SHIPMENT state, deliberately not an order status
        // (what happens to the order after a bounce is the operator's decision),
        // so it cannot ride the normal order_status search and filters here.
        if ($request->boolean('has_rto')) {
            $repository = $repository->scopeQuery(
                fn ($q) => $q->whereHas('shipments', fn ($s) => $s->where('status', 'rto'))
            );
        }
        $orders = $repository->paginate($limit)->withQueryString();

        // The order LIST grew large enough to get truncated mid-stream (the admin
        // orders list then showed empty). The Order model eager-loads
        // products.variation_options + customer on every order AND its children
        // (each child being an Order). After split-orders-by-vertical every order
        // has children, so ~3.3 KB/order × 20 blew past the response limit.
        //
        // The list table only renders: products.length, customer name/email, and
        // children.length (the expand icon — child contents are loaded separately
        // by the order-detail page via fetchSingleOrder). So slim each order hard:
        //   products  → [{id}]            (only .length is used)
        //   customer  → {id, name, email} (only name/email are shown)
        //   children  → [{id}]            (only .length is used)
        //   heavy JSON (addresses, payment intent, note) → hidden
        // This drops each order to a few hundred bytes so it can never truncate.
        $idsOnly = function ($model, string $relation) {
            if ($model->relationLoaded($relation)) {
                $model->setRelation($relation, $model->{$relation}->map(fn ($r) => ['id' => $r->id])->values());
            }
        };
        $orders->getCollection()->transform(function ($order) use ($idsOnly) {
            $idsOnly($order, 'products');
            $idsOnly($order, 'children');
            if ($order->relationLoaded('customer') && $order->customer) {
                $c = $order->customer;
                $order->setRelation('customer', ['id' => $c->id, 'name' => $c->name, 'email' => $c->email]);
            }
            $order->makeHidden(['shipping_address', 'billing_address', 'payment_intent_info', 'note']);
            return $order;
        });

        return $orders;
    }

    /**
     * fetchOrders
     *
     * @param mixed $request
     * @return object
     */
    public function fetchOrders(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }

        switch ($user) {
            case $user->hasPermissionTo(Permission::SUPER_ADMIN):
                return $this->repository->with(['children' => fn ($q) => $q->without('customer', 'products')])->where('id', '!=', null)->where('parent_id', '=', null);
                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):
                if ($this->repository->hasPermission($user, $request->shop_id)) {
                    return $this->vendorScopedOrders([(int) $request->shop_id]);
                } else {
                    return $this->vendorScopedOrders($user->shops->pluck('id')->map(fn ($i) => (int) $i)->all());
                }
                break;

            case $user->hasPermissionTo(Permission::STAFF):
                if ($this->repository->hasPermission($user, $request->shop_id)) {
                    return $this->vendorScopedOrders([(int) $request->shop_id]);
                } else {
                    return $this->vendorScopedOrders([(int) $user->shop_id]);
                }
                break;

            default:
                return $this->repository->with(['children' => fn ($q) => $q->without('customer', 'products')])->where('customer_id', '=', $user->id)->where('parent_id', '=', null);
                break;
        }
    }

    /**
     * Child orders a vendor may see. Single-shop model: catalog products (and so the
     * per-vertical child orders) belong to the master PlantAtHome shop — a vendor's
     * claim to an order comes from the ASSIGNMENT layer (parent order_items with
     * assigned_shop_id = their shop, for products in this child order). Legacy
     * child orders that carry the vendor's own shop_id keep matching too.
     */
    private function vendorScopedOrders(array $shopIds)
    {
        $shopIds = array_values(array_filter(array_map('intval', $shopIds)));
        return $this->repository
            ->with(['children' => fn ($q) => $q->without('customer', 'products')])
            ->where('parent_id', '!=', null)
            ->where(function ($q) use ($shopIds) {
                $q->whereIn('shop_id', $shopIds) // legacy vendor-owned child orders
                    ->orWhereExists(function ($sub) use ($shopIds) {
                        $sub->selectRaw('1')
                            ->from('order_items')
                            ->whereColumn('order_items.order_id', 'orders.parent_id')
                            ->whereIn('order_items.assigned_shop_id', $shopIds)
                            ->whereExists(function ($line) {
                                $line->selectRaw('1')
                                    ->from('order_product')
                                    ->whereColumn('order_product.order_id', 'orders.id')
                                    ->whereColumn('order_product.product_id', 'order_items.product_id');
                            });
                    });
            });

        // ********************* Old code *********************

        // if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN) && (!isset($request->shop_id) || $request->shop_id === 'undefined')) {
        //     return $this->repository->with(['children' => fn ($q) => $q->without('customer', 'products')])->where('id', '!=', null)->where('parent_id', '=', null); //->paginate($limit);
        // } else if ($this->repository->hasPermission($user, $request->shop_id)) {
        //     // if ($user && $user->hasPermissionTo(Permission::STORE_OWNER)) {
        //     return $this->repository->with(['children' => fn ($q) => $q->without('customer', 'products')])->where('shop_id', '=', $request->shop_id)->where('parent_id', '!=', null); //->paginate($limit);
        //     // } elseif ($user && $user->hasPermissionTo(Permission::STAFF)) {
        //     //     return $this->repository->with(['children' => fn ($q) => $q->without('customer', 'products')])->where('shop_id', '=', $request->shop_id)->where('parent_id', '!=', null); //->paginate($limit);
        //     // }
        // } else {
        //     return $this->repository->with(['children' => fn ($q) => $q->without('customer', 'products')])->where('customer_id', '=', $user->id)->where('parent_id', '=', null); //->paginate($limit);
        // }
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param OrderCreateRequest $request
     * @return LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     * @throws MarvelException
     */
    public function store(OrderCreateRequest $request)
    {
        try {
            // Checkout idempotency: a duplicate POST with the same Idempotency-Key
            // (double-click, network retry, refresh) returns the ORIGINAL order.
            // Pre-check for the fast path; the unique index on orders.idempotency_key
            // is the authoritative guard when two requests race past it.
            $idempotencyKey = substr(trim((string) $request->header('Idempotency-Key', '')), 0, 80);
            $customerId = $request->user()?->id;
            if ($idempotencyKey !== '') {
                $existing = $this->repository->findByIdempotencyKey($idempotencyKey, $customerId);
                if ($existing) {
                    $existing->makeVisible('tracking_token');
                    return $existing;
                }
                $request->merge(['idempotency_key' => $idempotencyKey]);
            }

            try {
                $order = DB::transaction(fn () => $this->repository->storeOrder($request, $this->settings));
            } catch (\Illuminate\Database\QueryException $e) {
                // Lost the idempotency race: the concurrent request committed first.
                // 23000 = integrity violation; match the column so an unrelated
                // constraint failure still surfaces normally.
                $isKeyRace = $idempotencyKey !== ''
                    && (string) $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'idempotency_key');
                if ($isKeyRace) {
                    $existing = $this->repository->findByIdempotencyKey($idempotencyKey, $customerId);
                    if ($existing) {
                        $existing->makeVisible('tracking_token');
                        return $existing;
                    }
                }
                throw $e;
            }
            // Create Intent AFTER the commit — a PSP network call must not hold
            // the transaction's row locks, and a provider failure must never
            // void the placed order (the customer retries from Pay Now).
            if (
                $order instanceof \Marvel\Database\Models\Order
                && !in_array($order->payment_gateway, [
                    \Marvel\Enums\PaymentGatewayType::CASH,
                    \Marvel\Enums\PaymentGatewayType::CASH_ON_DELIVERY,
                    \Marvel\Enums\PaymentGatewayType::FULL_WALLET_PAYMENT,
                ])
            ) {
                try {
                    $order['payment_intent'] = $this->repository->processPaymentIntent($request, $this->settings);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('order-create payment-intent failed; order kept payable', [
                        'gateway'         => $order->payment_gateway,
                        'tracking_number' => $order->tracking_number,
                        'error'           => $e->getMessage(),
                    ]);
                    $order['payment_intent'] = null;
                }
            }
            // Surface the per-order token to the buyer's client (and only here) so the
            // storefront can carry it to the order-confirmation page. It stays hidden
            // in every other response (list/detail) — see Order::$hidden.
            if ($order instanceof \Marvel\Database\Models\Order) {
                $order->makeVisible('tracking_token');
            }
            return $order;
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $th->getMessage());
        }
    }

    /**
     * Display the specified resource.
     *
     * @param Request $request
     * @param $params
     * @return JsonResponse
     * @throws MarvelException
     */
    public function show(Request $request, $params)
    {
        $request["tracking_number"] = $params;
        try {
            return $this->fetchSingleOrder($request);
        } catch (MarvelException $e) {
            throw new MarvelException($e->getMessage());
        }
    }

    /**
     * fetchSingleOrder
     *
     * @param mixed $request
     * @return void
     * @throws MarvelException
     */
    public function fetchSingleOrder(Request $request)
    {
        $user = $request->user() ?? null;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $orderParam = $request->tracking_number ?? $request->id;
        try {
            $order = $this->repository->where('language', $language)->with([
                'products',
                'shop',
                'vertical',
                'children.shop',
                'children.vertical',
                'children.products',
                'wallet_point',
            ])->where('id', $orderParam)->orWhere('tracking_number', $orderParam)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }

        // Create Intent
        if (!in_array($order->payment_gateway, [
            PaymentGatewayType::CASH, PaymentGatewayType::CASH_ON_DELIVERY, PaymentGatewayType::FULL_WALLET_PAYMENT
        ])) {
            // $order['payment_intent'] = $this->processPaymentIntent($request, $this->settings);
            $order['payment_intent'] = $this->attachPaymentIntent($orderParam);
        }

        $providedToken = (string) ($request->query('token') ?? $request->input('token') ?? '');

        if (!$order->customer_id) {
            // GUEST order (no owner to authorise against). New orders carry a per-order
            // secret token; require it so an attacker can't harvest a buyer's order by
            // enumerating tracking numbers. On any mismatch we behave EXACTLY like a
            // missing order (404) so we don't even confirm the order exists.
            if (!empty($order->tracking_token)) {
                if ($providedToken === '' || !hash_equals((string) $order->tracking_token, $providedToken)) {
                    throw new ModelNotFoundException(NOT_FOUND);
                }
                $order->unsetRelation('customer');
                return $order;
            }
            // Legacy guest order (created before tokens existed): keep the hardened
            // PII-stripped public fallback so old emailed links keep working.
            $order->makeHidden(['billing_address', 'customer_contact']);
            $order->unsetRelation('customer');
            return $order;
        }

        // REGISTERED-customer order: owner, the fulfilling vendor, or a super-admin only.
        $isSuperAdmin = $user && $user->hasPermissionTo(Permission::SUPER_ADMIN);
        $isOwner      = $user && ((int) $user->id === (int) $order->customer_id);
        $isVendor     = ($user && isset($order->shop_id) && $this->repository->hasPermission($user, $order->shop_id))
            // Single-shop model: the fulfilling vendor's claim comes from the assignment
            // layer (order_items.assigned_shop_id), not the order's (master) shop_id.
            || $this->vendorHasAssignment($user, $order);
        if ($isSuperAdmin || $isOwner || $isVendor) {
            return $order;
        }
        // Not permitted — reveal NOTHING about whether this order exists (404, not 403).
        throw new ModelNotFoundException(NOT_FOUND);
    }

    /** Whether any of the caller's shops is assigned an item of this order (or its parent). */
    private function vendorHasAssignment($user, $order): bool
    {
        try {
            if (!$user || !($user->hasPermissionTo(Permission::STORE_OWNER) || $user->hasPermissionTo(Permission::STAFF))) {
                return false;
            }
            $shopIds = $user->hasPermissionTo(Permission::STORE_OWNER)
                ? $user->shops->pluck('id')->map(fn ($i) => (int) $i)->all()
                : array_filter([(int) $user->shop_id]);
            if (empty($shopIds)) {
                return false;
            }
            $orderId = $order->parent_id ? (int) $order->parent_id : (int) $order->id;
            return \Marvel\Database\Models\OrderItem::where('order_id', $orderId)
                ->whereIn('assigned_shop_id', $shopIds)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * findByTrackingNumber
     *
     * @param mixed $request
     * @param mixed $tracking_number
     * @return void
     */
    public function findByTrackingNumber(Request $request, $tracking_number)
    {
        $user = $request->user() ?? null;
        $providedToken = (string) ($request->query('token') ?? $request->input('token') ?? '');
        try {
            $order = $this->repository->with(['products', 'children.shop', 'wallet_point', 'payment_intent'])
                ->findOneByFieldOrFail('tracking_number', $tracking_number);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }

        if ($order->customer_id === null) {
            // GUEST order — require the per-order token (new orders); fall back to the
            // PII-stripped public view only for legacy token-less orders.
            if (!empty($order->tracking_token)) {
                if ($providedToken === '' || !hash_equals((string) $order->tracking_token, $providedToken)) {
                    throw new MarvelException(NOT_FOUND);
                }
                return $order;
            }
            $order->makeHidden(['billing_address', 'customer_contact']);
            $order->unsetRelation('customer');
            return $order;
        }
        if ($user && ((int) $user->id === (int) $order->customer_id || $user->can('super_admin'))) {
            return $order;
        }
        // Hide existence from everyone else (404, never a 403 that confirms it exists).
        throw new MarvelException(NOT_FOUND);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param OrderUpdateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(OrderUpdateRequest $request, $id)
    {
        try {
            $request["id"] = $id;
            return $this->updateOrder($request);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE, $e->getMessage());
        }
    }

    public function updateOrder(OrderUpdateRequest $request)
    {
        return $this->repository->updateOrder($request);
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
            $order = $this->repository->findOrFail($id);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }
        // SECURITY: the destroy route is role-gated (staff|store_owner) but had NO per-order
        // ownership check, so any store owner/staff could delete ANY shop's orders by id.
        // Require super-admin OR ownership of the order's shop (mirrors updateOrder's gate).
        $user = $request->user();
        $authorized = $user && (
            $user->hasPermissionTo(Permission::SUPER_ADMIN)
            || (isset($order->shop_id) && $this->repository->hasPermission($user, $order->shop_id))
        );
        if (!$authorized) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        return $order->delete();
    }

    /**
     * F3 — pin/unpin an order so it floats to the top of every order listing.
     * Toggles when `is_pinned` is omitted. Same ownership gate as updateOrder /
     * destroy: super-admin, or a store owner/staff member of the order's shop.
     *
     * @param Request $request
     * @param int     $id
     * @return Order
     */
    public function pin(Request $request, $id)
    {
        try {
            $order = $this->repository->findOrFail($id);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }

        $user = $request->user();
        $authorized = $user && (
            $user->hasPermissionTo(Permission::SUPER_ADMIN)
            || (isset($order->shop_id) && $this->repository->hasPermission($user, $order->shop_id))
        );
        if (!$authorized) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }

        $request->validate(['is_pinned' => 'sometimes|boolean']);
        $pinned = $request->has('is_pinned')
            ? $request->boolean('is_pinned')
            : !$order->is_pinned;

        return $this->repository->togglePin($order, $pinned);
    }

    /**
     * Export order dynamic url
     *
     * @param Request $request
     * @param int $shop_id
     * @return string
     */
    public function exportOrderUrl(Request $request, $shop_id = null)
    {
        try {
            $user = $request->user();

            if ($user && !$this->repository->hasPermission($user, $request->shop_id)) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }

            $dataArray = [
                'user_id' => $user->id,
                'token' => Str::random(16),
                'payload' => $request->shop_id
            ];
            $newToken = DownloadToken::create($dataArray);

            return route('export_order.token', ['token' => $newToken->token]);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }

    /**
     * Export order to excel sheet
     *
     * @param string $token
     * @return void
     */
    public function exportOrder($token)
    {
        $shop_id = 0;
        try {
            $downloadToken = DownloadToken::where('token', $token)->first();

            $shop_id = $downloadToken->payload;
            $downloadToken->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(TOKEN_NOT_FOUND);
        }

        try {
            return Excel::download(new OrderExport($this->repository, $shop_id), 'orders.xlsx');
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Export order dynamic url
     *
     * @param Request $request
     * @param int $shop_id
     * @return string
     */
    public function downloadInvoiceUrl(Request $request)
    {

        try {
            $user = $request->user();
            if ($user && !$this->repository->hasPermission($user, $request->shop_id)) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
            if (empty($request->order_id)) {
                throw new NotFoundHttpException(NOT_FOUND);
            }
            $language = $request->language ?? DEFAULT_LANGUAGE;
            $isRTL = $request->is_rtl ?? false;

            $translatedText = $this->formatInvoiceTranslateText($request->translated_text);

            $payload = [
                'user_id' => $user->id,
                'order_id' => intval($request->order_id),
                'language' => $language,
                'translated_text' => $translatedText,
                'is_rtl' => $isRTL
            ];

            $data = [
                'user_id' => $user->id,
                'token' => Str::random(16),
                'payload' => serialize($payload)
            ];

            $newToken = DownloadToken::create($data);

            return route('download_invoice.token', ['token' => $newToken->token]);
        } catch (MarvelException $e) {
            throw new MarvelException($e->getMessage());
        }
    }

    /**
     * Export order to excel sheet
     *
     * @param string $token
     * @return void
     */
    public function downloadInvoice($token)
    {
        $payloads = [];
        try {
            $downloadToken = DownloadToken::where('token', $token)->firstOrFail();
            $payloads = unserialize($downloadToken->payload);
            $downloadToken->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(TOKEN_NOT_FOUND);
        }

        try {
            $settings = Settings::getData($payloads['language']);
            $order = $this->repository->with(['products', 'children.shop', 'wallet_point', 'parent_order'])->where('id', $payloads['order_id'])->orWhere('tracking_number', $payloads['order_id'])->firstOrFail();

            $invoiceData = [
                'order' => $order,
                'settings' => $settings,
                'translated_text' => $payloads['translated_text'],
                'is_rtl' => $payloads['is_rtl'],
                'language' => $payloads['language'],
            ];
            $pdf = PDF::loadView('pdf.order-invoice', $invoiceData);
            $options = new Options();
            // setIsPhpEnabled(true) let the renderer EXECUTE <script type="text/php"> inside
            // the document, on a route reachable with only a download token, whose data
            // includes customer-supplied address and note text. Nothing here needs it — the
            // invoice templates carry no text/php block and escape every field — so it is off:
            // otherwise the first future template edit that renders a raw field is RCE.
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $pdf->getDomPDF()->setOptions($options);

            $filename = 'invoice-order-' . $payloads['order_id'] . '.pdf';

            return $pdf->download($filename);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * submitPayment
     *
     * @param mixed $request
     * @return void
     * @throws Exception
     */
    public function submitPayment(Request $request): void
    {
        $tracking_number = $request->tracking_number ?? null;
        try {
            $order = $this->repository->with(['products', 'children.shop', 'wallet_point', 'payment_intent'])
                ->findOneByFieldOrFail('tracking_number', $tracking_number);

            $isFinal = $this->checkOrderStatusIsFinal($order);
            if ($isFinal) return;

            switch ($order->payment_gateway) {

                case PaymentGatewayType::STRIPE:
                    $this->stripe($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::PAYPAL:
                    $this->paypal($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::MOLLIE:
                    $this->mollie($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::RAZORPAY:
                    $this->razorpay($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::SSLCOMMERZ:
                    $this->sslcommerz($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::PAYSTACK:
                    $this->paystack($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::PAYMONGO:
                    $this->paymongo($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::XENDIT:
                    $this->xendit($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::IYZICO:
                    $this->iyzico($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::BKASH:
                    $this->bkash($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::FLUTTERWAVE:
                    $this->flutterwave($order, $request, $this->settings);
                    break;
            }
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }
}
