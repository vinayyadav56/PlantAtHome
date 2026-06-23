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
    ];
    /**
     * @var string[]
     */
    protected array $dataArray = [
        'tracking_number',
        'tracking_token',
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
        'note',
        'vendor_cost_total',
        // Operations / courier-area order flags (set by the storefront when the
        // shopper continues from a non-serviceable area).
        'is_non_serviceable_order',
        'detected_city',
        'serviceable_city',
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
    public function storeOrder($request, $settings): mixed
    {
        $request['tracking_number'] = $this->generateTrackingNumber();
        // Per-order secret: required to view a GUEST order (no customer_id). High
        // entropy so it can't be guessed; carried by the redirect + the order email.
        $request['tracking_token'] = Str::random(48);

        // Operations Control Center — final guard: never create an order
        // containing a vertical that's unavailable in the shipping city.
        $this->assertVerticalsAvailable($request);
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
        // Server-authoritative pricing: for products with a vendor cost sheet, charge
        // the margin-over-cost selling price (computed here, not trusted from the
        // client). Products without a cost sheet are untouched — nothing changes for
        // them. Uses the customer's shared coordinates when present (coarse for now).
        $custLatLng = $this->customerLatLngFromRequest($request);
        $request['products'] = (new PricingService())->repriceLines((array) $request['products'], $custLatLng);
        $request['amount'] = $this->calculateSubtotal($request['products']);

        // Snapshot the vendor cost for true-profit reporting (selling − cost − dp − fees).
        // Hidden from customers; persisted on the parent + split across suborders.
        $request['vendor_cost_total'] = $this->computeVendorCostTotal($request['products'], $custLatLng);

        // H8 — block oversell at order creation. The atomic decrement keeps the
        // stock column from going negative, but without this the order would still
        // be created + charged for unfulfillable items. Runs inside the order's
        // DB::transaction (OrderController::store) so a failure rolls the order back.
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

        $request['delivery_fee'] = ($freeShipByCoupon || $freeShipByThreshold)
            ? 0
            : (float) $checkout->calculateShippingCharge($request, $request['amount']);
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
                return $order;
            }
        } else {
            $amount = round($request['paid_total'], 2);
        }

        $order = $this->createOrder($request);

        if (($useWalletPoints || $request->isFullWalletPayment) && $user) {
            $this->storeOrderWalletPoint(round($request['paid_total'], 2) - $amount, $order->id);
            $this->manageWalletAmount(round($request['paid_total'], 2), $user->id);
        }

        $eligible = $this->checkOrderEligibility();
        if (!$eligible) {
            throw new MarvelBadRequestException('COULD_NOT_PROCESS_THE_ORDER_PLEASE_CONTACT_WITH_THE_ADMIN');
        }
        // Create Intent
        if (!in_array($order->payment_gateway, [
            PaymentGatewayType::CASH, PaymentGatewayType::CASH_ON_DELIVERY, PaymentGatewayType::FULL_WALLET_PAYMENT
        ])) {
            $order['payment_intent'] = $this->processPaymentIntent($request, $settings);
        }


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
     * @param $request
     * @return array|LengthAwarePaginator|Collection|mixed
     */
    protected function createOrder($request)
    {
        try {
            $orderInput = $request->only($this->dataArray);
            $order = $this->create($orderInput);
            $products = $this->processProducts($request['products'], $request['customer_id'], $order);
            $order->products()->attach($products);
            // P4 dual-write: mirror the cart lines into order_items (the single-customer-order
            // model) alongside the legacy per-vertical child orders. Wrapped so a failure here
            // can never break order creation — order_items is additive shadow data for now.
            try {
                (new \Marvel\Services\OrderItemService())->writeForOrder($order, $products);
            } catch (\Throwable $e) {
                // additive shadow — swallow
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
     * charged total uses (vendor cost-sheet margin → variation → product, with
     * sale_price winning over price). Never trusts the client's unit_price.
     */
    protected function serverUnitPrice(array $item): float
    {
        $voId = $item['variation_option_id'] ?? null;
        $margin = $this->vendorMarginUnitPrice($item['product_id'] ?? null, $voId);
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
        foreach ($products as $key => $product) {
            if (!isset($product['variation_option_id'])) {
                $product['variation_option_id'] = null;
            }
            // H7 — persist the SERVER-authoritative line price (the exact same
            // logic the charged total uses), never the client-sent unit_price/
            // subtotal. Keeps the order record/invoice/settlement consistent.
            $unit = $this->serverUnitPrice($product);
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
                'customer_contact' => $request->customer_contact,
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
     * Σ (nearest vendor cost × qty) across the cart — the hidden cost basis used
     * for per-order profit. 0 when no vendor cost sheets exist for the products.
     */
    protected function computeVendorCostTotal($products, ?array $latLng = null): float
    {
        $service = new PricingService();
        $total = 0.0;
        foreach ($products as $item) {
            $pid = $item['product_id'] ?? null;
            if (!$pid) {
                continue;
            }
            $vo   = $item['variation_option_id'] ?? null;
            $cost = $service->vendorCost((int) $pid, $vo !== null ? (int) $vo : null, $latLng);
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

    /**
     * Helper method to generate unique tracking number
     *
     * @return string
     * @throws Exception
     */
    public function generateTrackingNumber(): string
    {
        $today = date('Ymd');
        $trackingNumbers = Order::where('tracking_number', 'like', $today . '%')->pluck('tracking_number');

        do {
            $trackingNumber = $today . random_int(100000, 999999);
        } while ($trackingNumbers->contains($trackingNumber));

        return $trackingNumber;
    }

    public function checkOrderEligibility(): bool
    {
        $settings = Settings::getData();
        $useMustVerifyLicense = isset($settings->options['app_settings']['trust']) ? $settings->options['app_settings']['trust'] : false;
        return $useMustVerifyLicense;
    }
}
