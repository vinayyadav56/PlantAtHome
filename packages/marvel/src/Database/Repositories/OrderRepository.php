<?php


namespace Marvel\Database\Repositories;

use Carbon\Carbon;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderedFile;
use Marvel\Database\Models\OrderWalletPoint;
use Marvel\Database\Models\Wallet;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Marvel\Database\Models\Variation;
use Marvel\Services\PricingService;
use Marvel\Enums\CouponType;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductType;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Enums\PaymentStatus;
use Marvel\Events\OrderCreated;
use Marvel\Events\OrderProcessed;
use Marvel\Events\OrderReceived;
use Marvel\Exceptions\MarvelBadRequestException;
use Marvel\Traits\CalculatePaymentTrait;
use Marvel\Traits\OrderManagementTrait;
use Marvel\Traits\OrderStatusManagerWithPaymentTrait;
use Marvel\Traits\PaymentTrait;
use Marvel\Traits\WalletsTrait;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class OrderRepository extends BaseRepository
{
    use WalletsTrait,
        CalculatePaymentTrait,
        OrderManagementTrait,
        OrderStatusManagerWithPaymentTrait,
        PaymentTrait;
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'tracking_number' => 'like',
        'shop_id',
        'language',
        'order_status',   // enables vendor "rejected orders" (cancelled/refunded) filtering
        'delivery_mode',  // enables vendor/admin "courier orders" filtering
        'is_pinned',      // F3: allows filtering pinned orders if ever needed
        'payment_status', // admin transactions page: payment-lifecycle filter
    ];
    /**
     * @var string[]
     */
    protected array $dataArray = [
        'tracking_number',
        'tracking_token',
        // Client-supplied checkout idempotency key (parent orders only; children
        // build their own input array in createChildOrder and never inherit it).
        'idempotency_key',
        'customer_id',
        'shop_id',
        'language',
        'order_status',
        'payment_status',
        'amount',
        'sales_tax',
        'paid_total',
        'total',
        'delivery_time',
        'payment_gateway',
        'altered_payment_gateway',
        'discount',
        'coupon_id',
        'logistics_provider',
        'billing_address',
        'shipping_address',
        'delivery_fee',
        'customer_contact',
        'customer_name',
        'customer_email',
        'note',
        'vendor_cost_total',
        // Operations / courier-area order flags (set by the storefront when the
        // shopper continues from a non-serviceable area).
        'is_non_serviceable_order',
        'detected_city',
        'serviceable_city',
        // Delivery Coverage snapshot ({pincode, shop_id, source, geo names,
        // coverage_ver, checked_at}) — computed server-side in storeOrder().
        'delivery_coverage',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Order::class;
    }

    /**
     * Store order
     *
     * @param $request
     * @param $settings
     * @return LengthAwarePaginator|JsonResponse|Collection|mixed
     * @throws Exception
     */
    /**
     * Whether the orders.tracking_token column exists yet. Memoised per request;
     * background migrations can lag the code deploy, so guard column usage.
     */
    protected static ?bool $supportsTrackingToken = null;

    protected function ordersSupportTrackingToken(): bool
    {
        if (static::$supportsTrackingToken === null) {
            static::$supportsTrackingToken = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'tracking_token');
        }
        return static::$supportsTrackingToken;
    }

    /** Same deploy-lag guard as tracking_token — migrations run in the background. */
    protected static ?bool $supportsIdempotencyKey = null;

    public function ordersSupportIdempotencyKey(): bool
    {
        if (static::$supportsIdempotencyKey === null) {
            static::$supportsIdempotencyKey = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'idempotency_key');
        }
        return static::$supportsIdempotencyKey;
    }

    /**
     * The order a previous request with this Idempotency-Key already created.
     * Scoped to the same customer (or to guest orders when unauthenticated) so
     * one user's key can never surface another user's order.
     */
    public function findByIdempotencyKey(string $key, ?int $customerId): ?Order
    {
        if ($key === '' || !$this->ordersSupportIdempotencyKey()) {
            return null;
        }
        return Order::where('idempotency_key', $key)
            ->whereNull('parent_id')
            ->when(
                $customerId !== null,
                fn ($q) => $q->where('customer_id', $customerId),
                fn ($q) => $q->whereNull('customer_id'),
            )
            ->first();
    }

    public function storeOrder($request, $settings): mixed
    {
        $request['tracking_number'] = $this->generateTrackingNumber();
        // Per-order secret: required to view a GUEST order (no customer_id). High
        // entropy so it can't be guessed; carried by the redirect + the order email.
        // Railway/EC2 run migrations in the BACKGROUND after the app starts serving
        // (see Order::ordersSupportPinning), so the column can lag the code deploy.
        // Guard the write so order creation never breaks while it catches up — until
        // then orders simply have no token and fall back to the legacy guest view.
        if ($this->ordersSupportTrackingToken()) {
            $request['tracking_token'] = Str::random(48);
        }
        // Idempotency key: only persist when the column exists (deploy lag) —
        // otherwise strip it so the insert can't fail on an unknown column.
        if (!empty($request['idempotency_key']) && !$this->ordersSupportIdempotencyKey()) {
            $request['idempotency_key'] = null;
        }

        // Operations Control Center — final guard: never create an order
        // containing a vertical that's unavailable in the shipping city.
        $this->assertVerticalsAvailable($request);

        // Shopping-City hard gate (redesign): when the client declares its shopping city,
        // the delivery address MUST belong to it — 422 otherwise (the storefront shows the
        // choose-another-address / change-city dialog). Old clients that send no
        // shopping_city are unaffected. On a match, the server stamps the canonical
        // shopping city (never trusting the client's serviceable_city claim).
        $checkoutGate = new CheckoutRepository();
        $mismatch = $checkoutGate->shoppingCityMismatch($request);
        if ($mismatch !== null) {
            // HttpResponseException so the structured payload reaches the client
            // verbatim (the marvel handler masks HttpException messages in prod).
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json($mismatch, 422)
            );
        }
        if (!empty($request['shopping_city'])) {
            // Stamp the CANONICAL city name (Gurgaon -> Gurugram), not the client's raw string.
            $cityKey = app(\Marvel\Services\AvailabilityService::class)
                ->normalizeCityKey((string) $request['shopping_city']);
            $canonCity = \Marvel\Database\Models\City::query()
                ->whereRaw('LOWER(name) = ?', [$cityKey])->value('name');
            $request['serviceable_city'] = $canonCity ?: trim((string) $request['shopping_city']);

            // Display-only policy: a city with NO nursery supply is browse-only —
            // ordering is blocked with a structured 422. Old clients that send no
            // shopping_city are unaffected; cityHasSupply fails open on any fault.
            $cityStock = $checkoutGate->shoppingCityOutOfStock($request);
            if ($cityStock !== null) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json($cityStock, 422)
                );
            }
        }
        // Deliver-to-someone-else: a recipient must be identifiable for the courier.
        if (($request['deliver_to'] ?? null) === 'someone_else') {
            $shipAddr = (array) ($request['shipping_address'] ?? []);
            if (empty($shipAddr['recipient_name']) || empty($shipAddr['recipient_phone'])) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json([
                        'code'    => 'RECIPIENT_REQUIRED',
                        'message' => 'Recipient name and phone are required when delivering to someone else.',
                    ], 422)
                );
            }
        }
        // $request->merge([
        //     'payable'         => $request['paid_total'], // amount to be paid through paymentGateway
        //     'wallet_currency' => 0
        // ]);

        $fullWalletOrCODPayment = $request?->isFullWalletPayment ? PaymentGatewayType::FULL_WALLET_PAYMENT : PaymentGatewayType::CASH_ON_DELIVERY;
        $payment_gateway_type = !empty($request->payment_gateway) ? $request->payment_gateway : $fullWalletOrCODPayment;

        switch ($payment_gateway_type) {
            case PaymentGatewayType::CASH_ON_DELIVERY:
                $request['order_status'] = OrderStatus::PROCESSING;
                $request['payment_status'] = PaymentStatus::CASH_ON_DELIVERY;
                break;

            case PaymentGatewayType::CASH:
                $request['order_status'] = OrderStatus::PROCESSING;
                $request['payment_status'] = PaymentStatus::CASH;
                break;

                // case PaymentGatewayType::FULL_WALLET_PAYMENT:
                //     $request['order_status'] = OrderStatus::PROCESSING;
                //     $request['payment_status'] = PaymentStatus::WALLET;
                //     break;

            default:
                $request['order_status'] = OrderStatus::PENDING;
                $request['payment_status'] = PaymentStatus::PENDING;
                break;
        }

        $useWalletPoints = isset($request->use_wallet_points) ? $request->use_wallet_points : false;
        if ($request->user() && $request->user()->hasPermissionTo(Permission::SUPER_ADMIN) && isset($request['customer_id'])) {
            $request['customer_id'] =  $request['customer_id'];
        } else {
            $request['customer_id'] = $request->user()->id ?? null;
        }
        try {
            $user = User::findOrFail($request['customer_id']);
            if ($user) {
                $request['customer_name'] = $user->name;
            }
        } catch (Exception $e) {
            $user = null;
        }

        if (!$user) {
            $settings = Settings::getData($request->language);
            if (isset($settings->options['guestCheckout']) && !$settings->options['guestCheckout']) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
        }

        // Admin toggles must actually gate behavior (they were UI-only):
        // maintenance blocks ordering for non-staff; COD-off refuses COD orders
        // a client submits directly (the storefront hides the option, but
        // hiding is not enforcement).
        $this->assertNotUnderMaintenance($request);
        $this->assertCodAllowed($request);
        // Server-authoritative pricing: for vendor-supplied products, charge the CITY
        // selling price (max vendor rate + PlantAtHome margin — computed here, not
        // trusted from the client). Products without vendor inventory are untouched.
        $custLatLng = $this->customerLatLngFromRequest($request);
        $custCity   = $this->customerCityFromRequest($request);
        $request['products'] = (new PricingService())->repriceLines((array) $request['products'], $custLatLng, $custCity);
        $request['amount'] = $this->calculateSubtotal($request['products'], $custCity);

        // Snapshot the expected vendor payout (lowest covering rate) for true-profit
        // reporting. Hidden from customers; persisted on the parent + split across suborders.
        $request['vendor_cost_total'] = $this->computeVendorCostTotal($request['products'], $custLatLng, $custCity);

        // Delivery Coverage snapshot — how the shipping pincode resolved at order
        // time (audit/debug; never gates). Best-effort: null on any miss/fault.
        $coverageSnapshot = $this->deliveryCoverageSnapshot($request);
        if ($coverageSnapshot !== null) {
            $request['delivery_coverage'] = $coverageSnapshot;
        }

        // H8 — oversell gating. NB: checkStock() below is a deliberate NO-OP
        // (CheckoutRepository::isInStock is stubbed — "city is the only gate"),
        // so this pre-check never fires. The ACTUAL oversell gate is the atomic
        // decrement in ProductInventoryDecrement: under the 'block' policy
        // (config shop.inventory_oversell_policy) a 0-row decrement throws
        // InsufficientStockException inside this same transaction and the whole
        // order rolls back; under 'log' (legacy) oversell only logs.
        $stockUnavailable = (new CheckoutRepository())->checkStock((array) $request['products']);
        if (!empty($stockUnavailable)) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, 'Some items in your cart are out of stock.');
        }

        // Discount is server-authoritative: it stays 0 unless a coupon is supplied
        // AND passes full validation here (a client can't send a bare `discount`).
        $request['discount'] = 0;
        if (isset($request->coupon_id)) {
            $coupon = Coupon::findOrFail($request['coupon_id']);
            // C3 — validate the coupon AT ORDER CREATION (approval + active window +
            // minimum cart), not only in the advisory `verify` preview. Use the
            // server-recomputed subtotal.
            $subtotal = (float) $request['amount'];
            if (!$coupon->is_approve || !$coupon->is_valid || $subtotal < (float) $coupon->minimum_cart_amount) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, 'This coupon is not valid for this order.');
            }
            // Item 5 — an authenticated-only coupon (`target`) must not be applied by a guest.
            // verifyCoupon enforces this in the preview, but a client can POST coupon_id
            // straight to order-create and skip the preview; enforce it server-side here too.
            if ($coupon->target && empty($user)) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, 'This coupon is only for signed-in customers.');
            }
            // Fail-fast on a clearly-exhausted usage-limited coupon before building the order.
            // The authoritative, race-safe consume happens after the order row exists (below).
            if ($coupon->isExhausted($user?->id)) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, 'This coupon has reached its usage limit.');
            }
            // Clamp the discount so it can never exceed the subtotal (no negative totals).
            $request['discount'] = min((float) $this->calculateDiscount($coupon, $subtotal), $subtotal);
        }

        // Server-authoritative delivery_fee + sales_tax — NEVER trust the client's figures
        // (a crafted request could send sales_tax=0/delivery_fee=0 and be charged only the
        // product subtotal, evading tax + shipping). Recompute with the EXACT same logic the
        // checkout `verify` preview uses, so an honest client sees no change and a tampered
        // one is corrected.
        $checkout = new CheckoutRepository();
        // NB: use a DISTINCT local ($cfg) — do NOT reuse the $settings parameter, which is the
        // controller-injected Settings model that processPaymentIntent($request, $settings) needs.
        $cfg = Settings::getData();
        $freeShipByCoupon    = isset($coupon) && $coupon->type === CouponType::FREE_SHIPPING_COUPON;
        $freeShipByThreshold = !empty($cfg['options']['freeShipping'])
            && isset($cfg['options']['freeShippingAmount'])
            && (float) $cfg['options']['freeShippingAmount'] <= (float) $request['amount'];

        // Fee cutover: Delivery Optimizer enabled ⇒ the consolidated flat fee (same pure
        // amount+settings function verify charged — zero drift). Null (flag off / failure)
        // ⇒ legacy per-product charge, byte-identical. Coupon/threshold-free always win.
        $optimizerFee = $checkout->optimizerFlatFee((float) $request['amount']);
        $request['delivery_fee'] = ($freeShipByCoupon || $freeShipByThreshold)
            ? 0
            : ($optimizerFee !== null
                ? $optimizerFee
                : (float) $checkout->calculateShippingCharge($request, $request['amount']));
        $request['sales_tax'] = (float) $checkout->calculateTax($request, $request['delivery_fee'], $request['amount']);

        // Floor at 0 — a payable total must never be negative (C3 defense-in-depth).
        $request['paid_total'] = max(0, $request['amount'] + $request['sales_tax'] + $request['delivery_fee'] - $request['discount']);
        $request['total'] = $request['paid_total'];
        if (($useWalletPoints || $request->isFullWalletPayment) && $user) {
            $wallet = $user->wallet;
            $amount = null;
            if (isset($wallet->available_points)) {
                $amount = round($request['paid_total'], 2) - $this->walletPointsToCurrency($wallet->available_points);
            }

            if ($amount !== null && $amount <= 0) {
                $request['order_status'] = OrderStatus::COMPLETED;
                $request['payment_gateway'] = PaymentGatewayType::FULL_WALLET_PAYMENT;
                $request['payment_status'] = PaymentStatus::SUCCESS;
                $order = $this->createOrder($request);
                $this->storeOrderWalletPoint($request['paid_total'], $order->id);
                $this->manageWalletAmount($request['paid_total'], $user->id);
                $this->consumeCouponIfAny($coupon ?? null, $order, $user);
                // Inventory MUST decrement on this path too — this early return
                // used to skip the OrderProcessed event entirely, so 100%-wallet
                // orders never deducted stock (while cancelling them still
                // RESTORED it, inflating counters).
                event(new OrderProcessed($order));
                return $order;
            }
        } else {
            $amount = round($request['paid_total'], 2);
        }

        $order = $this->createOrder($request);
        $this->consumeCouponIfAny($coupon ?? null, $order, $user);

        if (($useWalletPoints || $request->isFullWalletPayment) && $user) {
            $this->storeOrderWalletPoint(round($request['paid_total'], 2) - $amount, $order->id);
            $this->manageWalletAmount(round($request['paid_total'], 2), $user->id);
        }

        $eligible = $this->checkOrderEligibility();
        if (!$eligible) {
            throw new MarvelBadRequestException('COULD_NOT_PROCESS_THE_ORDER_PLEASE_CONTACT_WITH_THE_ADMIN');
        }
        // NB: the payment intent (a NETWORK call to the PSP) is created by
        // OrderController::store AFTER this transaction commits — holding the
        // coupon/wallet row locks open for a Razorpay round-trip meant any PSP
        // latency spike serialised every concurrent checkout behind it.

        if ($payment_gateway_type === PaymentGatewayType::CASH_ON_DELIVERY || $payment_gateway_type === PaymentGatewayType::CASH) {
            $this->orderStatusManagementOnCOD($order, OrderStatus::PENDING, OrderStatus::PROCESSING);
        } else {
            $this->orderStatusManagementOnPayment($order, OrderStatus::PENDING, PaymentStatus::PENDING);
        }

        event(new OrderProcessed($order));

        return $order;
    }


    /**
     * updateOrder
     *
     * @param  mixed $request
     * @return void
     */
    public function updateOrder($request)
    {
        $order = Order::findOrFail($request->id);
        $user = $request->user();
        if (isset($order->shop_id)) {
            if ($this->hasPermission($user, $order->shop_id)) {
                return $this->changeOrderStatus($order, $request->order_status);
            }
        } else if ($user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            return $this->changeOrderStatus($order, $request->order_status);
        } else {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }

    /**
     * F3 — pin/unpin an order. `pinned_at` records when it was pinned so the
     * most-recently-pinned order wins ties in the pinned-first global scope.
     *
     * @param  Order $order
     * @param  bool  $pinned
     * @return Order
     */
    public function togglePin(Order $order, bool $pinned): Order
    {
        $order->is_pinned = $pinned;
        $order->pinned_at = $pinned ? now() : null;
        $order->save();

        return $order;
    }

    /**
     * storeOrderWalletPoint
     *
     * @param  mixed $amount
     * @param  mixed $order_id
     * @return void
     */
    public function storeOrderWalletPoint($amount, $order_id)
    {
        if ($amount > 0) {
            OrderWalletPoint::create(['amount' =>  $amount, 'order_id' =>  $order_id]);
        }
    }


    /**
     * manageWalletAmount
     *
     * @param  mixed $total
     * @param  mixed $customer_id
     * @return void
     */
    public function manageWalletAmount($total, $customer_id)
    {
        try {
            $total = $this->currencyToWalletPoints($total);
            // Lock the wallet row for the life of the enclosing order transaction so two
            // concurrent orders for the same customer can't both read the same balance and
            // each spend it (lost-update double-spend). The debit is re-read under the lock.
            $wallet = Wallet::where('customer_id', $customer_id)->lockForUpdate()->first();
            if (!$wallet) {
                return;
            }
            $available_points = $wallet->available_points - $total >= 0 ? $wallet->available_points - $total : 0;
            if ($available_points === 0) {
                $spend = $wallet->points_used + $wallet->available_points;
            } else {
                $spend = $wallet->points_used + $total;
            }
            $wallet->available_points = $available_points;
            $wallet->points_used = $spend;
            $wallet->save();
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Record the coupon redemption for a just-created order, race-safe and idempotent.
     * Runs inside OrderController::store's DB::transaction, so CouponRepository::consume's
     * row lock holds until the order commits — a concurrent order using the same
     * single-use coupon blocks here and is rejected if the slot is already taken, which
     * rolls its whole order back. No-op when the order carries no coupon.
     */
    private function consumeCouponIfAny($coupon, $order, $user): void
    {
        if (empty($coupon) || empty($order?->id)) {
            return;
        }
        app(\Marvel\Database\Repositories\CouponRepository::class)
            ->consume($coupon, (int) $order->id, $user?->id);
    }

    /**
     * @param $request
     * @return array|LengthAwarePaginator|Collection|mixed
     */
    protected function createOrder($request)
    {
        try {
            $orderInput = $request->only($this->dataArray);
            // Deploy-lag guard: migrations run in the background after deploy —
            // an INSERT naming a not-yet-migrated column would fail the order.
            try {
                if (isset($orderInput['customer_email'])
                    && !\Illuminate\Support\Facades\Schema::hasColumn('orders', 'customer_email')) {
                    unset($orderInput['customer_email']);
                }
            } catch (\Throwable $e) {
                unset($orderInput['customer_email']);
            }
            $order = $this->create($orderInput);
            $products = $this->processProducts($request['products'], $request['customer_id'], $order);
            $order->products()->attach($products);
            // P4 dual-write: mirror the cart lines into order_items (the single-customer-order
            // model) alongside the legacy per-vertical child orders. Wrapped so a failure here
            // can never break order creation — order_items is additive shadow data for now.
            // Auto-assignment then routes every line to its best vendor (cheapest rate wins
            // within the scorer) and groups per-vendor shipments — this is how a supplier
            // learns about (and sees) the order under the single-shop model.
            try {
                $itemService = new \Marvel\Services\OrderItemService();
                $itemService->writeForOrder($order, $products);
                $itemService->assignAndGroup($order);
            } catch (\Throwable $e) {
                Log::warning('order auto-assignment failed (order kept; assign from admin)', [
                    'order_id' => $order->id ?? null,
                    'error'    => $e->getMessage(),
                ]);
            }
            $this->createChildOrder($order->id, $request);
            //  $this->calculateShopIncome($order);
            $invoiceData = $this->createInvoiceDataForEmail($request, $order);
            $customer = $request->user() ?? null;
            event(new OrderCreated($order, $invoiceData, $customer));
            return $order;
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * This function creates an array of data for an email invoice, including order information,
     * settings, translated text, and URL.
     * 
     * @param request This is an HTTP request object that contains information about the current
     * request being made to the server. It is used to retrieve data from the request, such as the
     * language and whether the text should be displayed right-to-left (RTL).
     * @param order The order object that contains information about the order, such as the customer
     * details, order items, and total amount.
     * 
     * @return array An array containing order data, settings data, translated text, RTL status,
     * language, and a URL.
     */
    public function createInvoiceDataForEmail($request, $order): array
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $isRTL = $request->is_rtl ?? false;

        $translatedText = $this->formatInvoiceTranslateText($request->invoice_translated_text);
        $settings = Settings::getData($language);
        return [
            'order'           => $order,
            'settings'        => $settings,
            'translated_text' => $translatedText,
            'is_rtl'          => $isRTL,
            'language'        => $language,
            // Guest orders need the per-order token in the link so the buyer can open
            // their confirmation from the email; registered orders are owner-gated.
            'url' => config('shop.shop_url') . '/orders/' . $order->tracking_number
                . (empty($order->customer_id) && !empty($order->tracking_token)
                    ? '?token=' . $order->tracking_token
                    : '')
        ];
    }

    /**
     * calculateShopIncome
     *
     * @param  mixed $parent_order
     * @return void
     */
    protected function calculateShopIncome($parent_order)
    {
        foreach ($parent_order->children as  $order) {
            $balance = Balance::where('shop_id', '=', $order->shop_id)->first();
            $adminCommissionRate = $balance->admin_commission_rate;
            $shop_earnings = ($order->total * (100 - $adminCommissionRate)) / 100;
            $balance->total_earnings = $balance->total_earnings + $shop_earnings;
            $balance->current_balance = $balance->current_balance + $shop_earnings;
            $balance->save();
        }
    }

    /**
     * Server-authoritative unit price for a cart line — the SAME resolution the
     * charged total uses (city vendor price → variation → product, with
     * sale_price winning over price). Never trusts the client's unit_price.
     */
    protected function serverUnitPrice(array $item, ?string $city = null): float
    {
        $voId = $item['variation_option_id'] ?? null;
        $margin = $this->vendorMarginUnitPrice($item['product_id'] ?? null, $voId, $city);
        if ($margin !== null) {
            return (float) $margin;
        }
        if (!empty($voId)) {
            $v = Variation::find($voId);
            return (float) ($v && $v->sale_price ? $v->sale_price : optional($v)->price);
        }
        $p = Product::find($item['product_id'] ?? null);
        return (float) ($p && $p->sale_price ? $p->sale_price : optional($p)->price);
    }

    /**
     * processProducts
     *
     * @param  mixed $products
     * @param  mixed $customer_id
     * @param  mixed $order
     * @return void
     */
    protected function processProducts($products, $customer_id, $order)
    {
        // City context for the vendor-price resolution — same city the charged total used.
        $addr = is_array($order->shipping_address) ? $order->shipping_address : (array) ($order->shipping_address ?? []);
        $orderCity = isset($addr['city']) && $addr['city'] !== '' ? (string) $addr['city'] : null;
        foreach ($products as $key => $product) {
            if (!isset($product['variation_option_id'])) {
                $product['variation_option_id'] = null;
            }
            // H7 — persist the SERVER-authoritative line price (the exact same
            // logic the charged total uses), never the client-sent unit_price/
            // subtotal. Keeps the order record/invoice/settlement consistent.
            $unit = $this->serverUnitPrice($product, $orderCity);
            $product['unit_price'] = $unit;
            $product['subtotal']   = $unit * (int) ($product['order_quantity'] ?? 1);
            $products[$key] = $product;

            try {
                if ($order->parent_id === null) {
                    $productData = Product::with('digital_file')->findOrFail($product['product_id']);

                    // if rental product
                    $isRentalProduct = $productData->is_rental;
                    if ($isRentalProduct) {
                        $this->processRentalProduct($product, $order->id);
                    }


                    if ($productData->product_type === ProductType::SIMPLE) {
                        $this->storeOrderedFile($productData, $product['order_quantity'], $customer_id, $order->tracking_number);
                    } else if ($productData->product_type === ProductType::VARIABLE) {
                        $variation_option = Variation::with('digital_file')->findOrFail($product['variation_option_id']);
                        $this->storeOrderedFile($variation_option, $product['order_quantity'], $customer_id, $order->tracking_number);
                    }
                }
            } catch (Exception $e) {
                throw $e;
            }
        }
        return $products;
    }


    /**
     * storeOrderedFile
     *
     * @param  mixed $item
     * @param  mixed $order_quantity
     * @param  mixed $customer_id
     * @return void
     */
    public function storeOrderedFile($item, $order_quantity, $customer_id, $order_tracking_number)
    {
        if ($item->is_digital) {
            $digital_file = $item->digital_file;
            for ($i = 0; $i < $order_quantity; $i++) {
                OrderedFile::create([
                    'purchase_key'    => Str::random(16),
                    'digital_file_id' => $digital_file->id,
                    'customer_id'     => $customer_id,
                    'tracking_number'  => $order_tracking_number
                ]);
            }
        }
    }

    /**
     * processRentalProduct
     *
     * @param  mixed $product
     * @param  mixed $orderId
     * @return void
     */
    protected function processRentalProduct($product, $orderId)
    {
        $product['from'] = Carbon::parse($product['from']);
        $product['to'] = Carbon::parse($product['to']);
        $product['booking_duration'] = $product['from']->diffAsCarbonInterval($product['to']);
        $product['order_id'] = $orderId;
        $product['language'] = $orderId;
        unset($product['unit_price']);
        unset($product['subtotal']);
        try {
            if ($product['variation_option_id'] === null) {
                $productData = Product::findOrFail($product['product_id']);
                unset($product['variation_option_id']);
                $product['language'] = $productData->language;
                if (TRANSLATION_ENABLED) {
                    $this->processAllTranslatedProducts($productData, $product);
                } else {
                    $productData->availabilities()->create($product);
                }
            } else {
                $variation_option = Variation::findOrFail($product['variation_option_id']);
                unset($product['variation_option_id']);
                if (TRANSLATION_ENABLED) {
                    $this->processAllTranslatedVariations($variation_option, $product);
                } else {
                    $variation_option->availabilities()->create($product);
                }
            }
        } catch (\Throwable $th) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
    }

    /**
     * processAllTranslatedProducts
     *
     * @param  mixed $product
     * @param  mixed $orderedItem
     * @return void
     */
    public function processAllTranslatedProducts($product, $orderedItem)
    {
        $translatedProducts = Product::where('sku', $product->sku)->get();
        foreach ($translatedProducts as $translatedProduct) {
            $orderedItem['language'] = $translatedProduct->language;
            $orderedItem['product_id'] = $translatedProduct->id;
            $translatedProduct->availabilities()->create($orderedItem);
        }
    }

    /**
     * processAllTranslatedVariations
     *
     * @param  mixed $variation
     * @param  mixed $orderedItem
     * @return void
     */
    public function processAllTranslatedVariations($variation, $orderedItem)
    {
        $translatedVariations = Variation::where('sku', $variation->sku)->get();
        foreach ($translatedVariations as $translatedVariation) {
            $orderedItem['language'] = $translatedVariation->language;
            $translatedVariation->availabilities()->create($orderedItem);
        }
    }


    /**
     * createChildOrder
     *
     * @param  mixed $id
     * @param  mixed $request
     * @return void
     * @throws Exception
     */
    public function createChildOrder($id, $request): void
    {
        $products = $request->products;
        $language = $request->language ?? DEFAULT_LANGUAGE;

        // Group cart items by VERTICAL (product type_id) → one suborder per vertical.
        // Each group also remembers the product's shop_id (single-shop today; this
        // composes with multi-vendor later by keying on shop_id + type_id).
        $groups = [];      // type_id => [cartProduct, ...]
        $groupShop = [];   // type_id => shop_id
        foreach ($products as $cartProduct) {
            $product = Product::findOrFail($cartProduct['product_id']);
            $verticalId = $product->type_id;
            $groups[$verticalId][] = $cartProduct;
            $groupShop[$verticalId] = $product->shop_id;
        }

        // Parent-level charges are spread across suborders by each vertical's
        // subtotal share, so per-vertical reporting + partial refunds are accurate.
        $cartSubtotal   = (float) array_sum(array_column($products, 'subtotal'));
        $cartSubtotal   = $cartSubtotal > 0 ? $cartSubtotal : 0.000001;
        $parentTax       = (float) ($request->sales_tax ?? 0);
        $parentDelivery  = (float) ($request->delivery_fee ?? 0);
        $parentDiscount  = (float) ($request->discount ?? 0);
        $parentVendorCost = (float) ($request->vendor_cost_total ?? 0);

        foreach ($groups as $verticalId => $cartProduct) {
            $amount   = array_sum(array_column($cartProduct, 'subtotal'));
            $share    = $amount / $cartSubtotal;
            $tax      = round($parentTax * $share, 2);
            $delivery = round($parentDelivery * $share, 2);
            $discount = round($parentDiscount * $share, 2);
            $vendorCost = round($parentVendorCost * $share, 2);
            $paidTotal = round($amount + $tax + $delivery - $discount, 2);

            $orderInput = [
                'tracking_number'  => $this->generateTrackingNumber(),
                'shop_id'          => $groupShop[$verticalId] ?? null,
                'vertical_type_id' => $verticalId,
                'order_status'     => $request->order_status,
                'payment_status'   => $request->payment_status,
                'customer_id'      => $request->customer_id,
                'shipping_address' => $request->shipping_address,
                'billing_address'  => $request->billing_address,
                'customer_contact' => $request->customer_contact
                    ?: optional(optional($request->user())->profile)->contact,
                'customer_name'    => $request->customer_name,
                'delivery_time'    => $request->delivery_time,
                'delivery_fee'     => $delivery,
                'sales_tax'        => $tax,
                'discount'         => $discount,
                'parent_id'        => $id,
                'amount'           => $amount,
                'total'            => $paidTotal,
                'paid_total'       => $paidTotal,
                'vendor_cost_total' => $vendorCost,
                'language'         => $language,
                "payment_gateway"  => $request->payment_gateway,
            ];

            $order = $this->create($orderInput);
            $order->products()->attach($this->processProducts($cartProduct,  $request['customer_id'],  $order));
            event(new OrderReceived($order));
        }
    }

    /**
     * Σ (expected vendor payout × qty) across the cart — the hidden cost basis used
     * for per-order profit (lowest covering vendor rate; assignment prefers the
     * cheapest vendor). 0 when no vendor inventory exists for the products.
     */
    protected function computeVendorCostTotal($products, ?array $latLng = null, ?string $city = null): float
    {
        $service = new PricingService();
        $total = 0.0;
        foreach ($products as $item) {
            $pid = $item['product_id'] ?? null;
            if (!$pid) {
                continue;
            }
            $vo   = $item['variation_option_id'] ?? null;
            $cost = $service->vendorCost((int) $pid, $vo !== null ? (int) $vo : null, $latLng, $city);
            if ($cost !== null) {
                $qty = (int) ($item['order_quantity'] ?? 1);
                $total += $cost * max($qty, 1);
            }
        }
        return round($total, 2);
    }

    /**
     * Operations Control Center — throw 503 if the order contains a vertical
     * unavailable in the shipping city. FAIL OPEN: no city / resolver error ⇒
     * allow (never block commerce on a fault). The throw is OUTSIDE the try so
     * a genuine block is never swallowed.
     */
    protected function assertVerticalsAvailable($request): void
    {
        $blocked = [];
        $city = null;
        try {
            $addr = $request['shipping_address'] ?? null;
            $city = is_array($addr) ? ($addr['city'] ?? null) : null;
            if (empty($city)) {
                return;
            }
            $ids = collect($request['products'] ?? [])->pluck('product_id')->filter()->unique()->all();
            if (empty($ids)) {
                return;
            }
            $svc = app(\Marvel\Services\ServiceAvailabilityService::class);
            foreach (\Marvel\Database\Models\Product::with('type')->whereIn('id', $ids)->get(['id', 'type_id']) as $p) {
                $slug = optional($p->type)->slug;
                if (!$slug) {
                    continue;
                }
                $res = $svc->resolve($slug, (string) $city);
                if (!$res['available']) {
                    // Keep the reason so the message can distinguish a GLOBAL /
                    // platform disable from one specific to this city.
                    $blocked[$slug] = $res['reason'] ?? 'unavailable';
                }
            }
        } catch (\Throwable $e) {
            return; // fail open
        }
        if (!empty($blocked)) {
            // Only name the city when the block is actually city-specific;
            // otherwise (a global/platform disable) "unavailable in <city>"
            // wrongly implies a delivery problem with that city.
            $cityScoped = count(array_filter(
                array_values($blocked),
                fn ($r) => is_string($r) && str_starts_with($r, 'city_')
            )) > 0;
            $message = $cityScoped
                ? 'Some items are currently unavailable in ' . $city . '. Please remove them and try again.'
                : 'Some items in your cart are currently unavailable. Please remove them and try again.';
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(503, $message);
        }
    }

    /**
     * Maintenance mode used to be a storefront overlay only — the APIs kept
     * accepting orders. When isUnderMaintenance is ON (and now is within the
     * optional start/until window), refuse order placement for everyone
     * except staff with a clear 503 the storefront can render.
     */
    protected function assertNotUnderMaintenance($request): void
    {
        try {
            $options = (array) (Settings::getData()->options ?? []);
            if (!($options['isUnderMaintenance'] ?? false)) {
                return;
            }
            $window = (array) ($options['maintenance'] ?? []);
            $now = now();
            if (!empty($window['start']) && $now->lt(\Carbon\Carbon::parse($window['start']))) {
                return; // scheduled but not started
            }
            if (!empty($window['until']) && $now->gt(\Carbon\Carbon::parse($window['until']))) {
                return; // window already over (toggle left on)
            }
        } catch (\Throwable $e) {
            return; // fail-open: a broken maintenance config must never block orders
        }

        $u = $request->user();
        if ($u && ($u->hasPermissionTo(Permission::SUPER_ADMIN) || $u->hasPermissionTo(Permission::STAFF))) {
            return;
        }
        throw new \Symfony\Component\HttpKernel\Exception\HttpException(
            503,
            'We are briefly down for maintenance — please try again shortly.'
        );
    }

    /**
     * COD-off must be ENFORCED, not just hidden in the payment grid — a client
     * posting payment_gateway=CASH_ON_DELIVERY directly was accepted.
     */
    protected function assertCodAllowed($request): void
    {
        if (!PaymentGatewayType::isCashOnDelivery($request['payment_gateway'] ?? null)) {
            return;
        }
        $options = (array) (Settings::getData()->options ?? []);
        if (array_key_exists('useCashOnDelivery', $options) && !$options['useCashOnDelivery']) {
            throw new HttpResponseException(response()->json([
                'code'    => 'COD_DISABLED',
                'message' => 'Cash on delivery is currently unavailable — please choose an online payment method.',
            ], 422));
        }
    }

    /** Customer coordinates from the order's shipping_address.location (if shared). */
    protected function customerLatLngFromRequest($request): ?array
    {
        $addr = $request['shipping_address'] ?? null;
        $loc  = is_array($addr) ? ($addr['location'] ?? null) : null;
        if (is_array($loc) && isset($loc['lat'], $loc['lng']) && is_numeric($loc['lat']) && is_numeric($loc['lng'])) {
            return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']];
        }
        return null;
    }

    /** Customer city from the order's shipping_address (drives the margin resolution). */
    protected function customerCityFromRequest($request): ?string
    {
        $addr = $request['shipping_address'] ?? null;
        $city = is_array($addr) ? ($addr['city'] ?? null) : null;
        return ($city !== null && trim((string) $city) !== '') ? (string) $city : null;
    }

    /**
     * Delivery Coverage snapshot for the order being created: the shipping
     * pincode, the first line's shop and the coverage tier that covered it
     * (state|district|city|manual|null), the pin's geo names from the postal
     * master, and the coverage projection version — enough to answer "why
     * was/wasn't this deliverable?" long after rules change. Best-effort and
     * additive: ANY missing precondition or fault returns null (no snapshot).
     */
    protected function deliveryCoverageSnapshot($request): ?array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('orders', 'delivery_coverage')) {
                return null; // migration lag — column not landed yet
            }
            $addr = $request['shipping_address'] ?? null;
            $a = is_array($addr) ? (array) ($addr['address'] ?? $addr) : [];
            $zip = preg_replace('/\D+/', '', (string) ($a['zip'] ?? $a['pincode'] ?? $a['postal_code'] ?? (is_array($addr) ? ($addr['zip'] ?? '') : '')));
            if (strlen($zip) < 6) {
                return null;
            }
            $service = \Marvel\Services\CoverageBridge::service();
            if ($service === null) {
                return null;
            }

            // First cart line's shop — the deterministic "who fulfils this" candidate.
            $shopId = null;
            foreach ((array) $request['products'] as $line) {
                $pid = (int) ($line['product_id'] ?? 0);
                if ($pid > 0) {
                    $sid = \Illuminate\Support\Facades\DB::table('products')->where('id', $pid)->value('shop_id');
                    if ($sid !== null) {
                        $shopId = (int) $sid;
                    }
                    break;
                }
            }

            $geo = \Illuminate\Support\Facades\DB::table('postal_codes')
                ->leftJoin('states', 'states.id', '=', 'postal_codes.state_id')
                ->leftJoin('districts', 'districts.id', '=', 'postal_codes.district_id')
                ->leftJoin('cities', 'cities.id', '=', 'postal_codes.city_id')
                ->where('postal_codes.pincode', $zip)
                ->first([
                    'states.name as state_name',
                    'districts.name as district_name',
                    'cities.name as city_name',
                ]);

            return [
                'pincode'      => $zip,
                'shop_id'      => $shopId,
                'source'       => $shopId !== null ? $service->resolveSource($shopId, $zip) : null,
                'state'        => $geo->state_name ?? null,
                'district'     => $geo->district_name ?? null,
                'city'         => $geo->city_name ?? null,
                'coverage_ver' => (int) \Illuminate\Support\Facades\Cache::get('coverage:ver', 0),
                'checked_at'   => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Helper method to generate unique tracking number
     *
     * @return string
     * @throws Exception
     */
    public function generateTrackingNumber(): string
    {
        // Per-attempt existence probe, not the old day-wide pluck: that loaded
        // EVERY tracking number of the day into memory on every order (O(n)
        // per checkout) and was a stale snapshot — blind to concurrent inserts.
        // 8 random digits = 90M/day space, so a retry is already rare; the
        // unique index on orders.tracking_number stays the final arbiter.
        $today = date('Ymd');
        do {
            $trackingNumber = $today . random_int(10000000, 99999999);
        } while (Order::where('tracking_number', $trackingNumber)->exists());

        return $trackingNumber;
    }

    public function checkOrderEligibility(): bool
    {
        $settings = Settings::getData();
        $useMustVerifyLicense = isset($settings->options['app_settings']['trust']) ? $settings->options['app_settings']['trust'] : false;
        return $useMustVerifyLicense;
    }
}
