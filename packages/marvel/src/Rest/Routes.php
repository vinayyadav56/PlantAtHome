<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Marvel\Enums\Permission;
use Marvel\Http\Controllers\AbusiveReportController;
use Marvel\Http\Controllers\AddressController;
use Marvel\Http\Controllers\AiController;
use Marvel\Http\Controllers\AnalyticsController;
use Marvel\Http\Controllers\CommandCenterController;
use Marvel\Http\Controllers\LocationController;
use Marvel\Http\Controllers\TrackingController;
use Marvel\Http\Controllers\AttachmentController;
use Marvel\Http\Controllers\AttributeController;
use Marvel\Http\Controllers\AttributeValueController;
use Marvel\Http\Controllers\AuthorController;
use Marvel\Http\Controllers\CategoryController;
use Marvel\Http\Controllers\CheckoutController;
use Marvel\Http\Controllers\ConversationController;
use Marvel\Http\Controllers\CouponController;
use Marvel\Http\Controllers\DeliveryTimeController;
use Marvel\Http\Controllers\DownloadController;
use Marvel\Http\Controllers\FaqsController;
use Marvel\Http\Controllers\FeedbackController;
use Marvel\Http\Controllers\FlashSaleController;
use Marvel\Http\Controllers\FlashSaleVendorRequestController;
use Marvel\Http\Controllers\ManufacturerController;
use Marvel\Http\Controllers\MessageController;
use Marvel\Http\Controllers\OrderController;
use Marvel\Http\Controllers\PaymentIntentController;
use Marvel\Http\Controllers\PaymentMethodController;
use Marvel\Http\Controllers\ProductController;
use Marvel\Http\Controllers\ProductImageController;
use Marvel\Http\Controllers\QuestionController;
use Marvel\Http\Controllers\RefundController;
use Marvel\Http\Controllers\ResourceController;
use Marvel\Http\Controllers\ReviewController;
use Marvel\Http\Controllers\SettingsController;
use Marvel\Http\Controllers\ShippingController;
use Marvel\Http\Controllers\ShopController;
use Marvel\Http\Controllers\TagController;
use Marvel\Http\Controllers\TaxController;
use Marvel\Http\Controllers\TypeController;
use Marvel\Http\Controllers\UserController;
use Marvel\Http\Controllers\WebHookController;
use Marvel\Http\Controllers\WishlistController;
use Marvel\Http\Controllers\WithdrawController;
use Marvel\Http\Controllers\LanguageController;
use Marvel\Http\Controllers\NotifyLogsController;
use Marvel\Http\Controllers\OwnershipTransferController;
use Marvel\Http\Controllers\RefundPolicyController;
use Marvel\Http\Controllers\RefundReasonController;
use Marvel\Http\Controllers\VoiceSearchController;
use Marvel\Http\Controllers\GardenController;
use Marvel\Http\Controllers\PlantDoctorController;
use Marvel\Http\Controllers\CarePlanController;
use Marvel\Http\Controllers\AiChatController;
use Marvel\Http\Controllers\DeliveryPincodeController;
use Marvel\Http\Controllers\DeliveryPartnerController;
use Marvel\Http\Controllers\PriceSheetController;
use Marvel\Http\Controllers\VendorInventoryController;
use Marvel\Http\Controllers\SettlementController;
use Marvel\Http\Controllers\ReportController;
use Marvel\Http\Controllers\CourierShipmentController;
use Marvel\Http\Controllers\CourierConfigController;
use Marvel\Http\Controllers\RolePermissionController;
use Marvel\Http\Controllers\BundleController;
use Marvel\Http\Controllers\LocationPriceController;
use Marvel\Http\Controllers\OrderAssignmentController;
use Marvel\Http\Controllers\DeliveryPartnerWithdrawController;
use Marvel\Http\Controllers\DeliveryPartnerEarningsController;
use Marvel\Http\Controllers\SystemController;
use Marvel\Http\Controllers\StoreNoticeController;
use Marvel\Http\Controllers\TermsAndConditionsController;

// use Illuminate\Support\Facades\Auth;

/**
 * ******************************************
 * Available Public Routes
 * ******************************************
 */

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::get('/email/verify/{id}/{hash}', [UserController::class, 'verifyEmail'])->name('verification.verify');

// Auth/OTP/password endpoints carry TIGHT per-route throttles (on top of the group-wide
// throttle:api) to blunt credential stuffing, OTP/SMS bombing, brute force and email flooding.
Route::post('/register', [UserController::class, 'register'])->middleware('throttle:5,1');
Route::post('/token', [UserController::class, 'token'])->middleware('throttle:10,1');
Route::post('/logout', [UserController::class, 'logout']);
Route::delete('/account', [UserController::class, 'deleteAccount'])->middleware('auth:sanctum');
Route::post('/forget-password', [UserController::class, 'forgetPassword'])->middleware('throttle:5,1');
Route::post('/verify-forget-password-token', [UserController::class, 'verifyForgetPasswordToken'])->middleware('throttle:10,1');
Route::post('/reset-password', [UserController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::post('/contact-us', [UserController::class, 'contactAdmin'])->middleware('throttle:5,1');
Route::post('/social-login-token', [UserController::class, 'socialLogin'])->middleware('throttle:15,1');
Route::post('/social-login/linkedin/exchange', [UserController::class, 'linkedinExchange'])->middleware('throttle:15,1');
Route::post('/send-otp-code', [UserController::class, 'sendOtpCode'])->middleware('throttle:5,1');
Route::post('/verify-otp-code', [UserController::class, 'verifyOtpCode'])->middleware('throttle:10,1');
Route::post('/otp-login', [UserController::class, 'otpLogin'])->middleware('throttle:10,1');
Route::get('top-authors', [AuthorController::class, 'topAuthor']);
Route::get('top-manufacturers', [ManufacturerController::class, 'topManufacturer']);
Route::get('popular-products', [ProductController::class, 'popularProducts']);
Route::get('best-selling-products', [ProductController::class, 'bestSellingProducts']);
Route::get('top-rated-products', [ProductController::class, 'topRatedProducts']);
Route::get('check-availability', [ProductController::class, 'checkAvailability']);
Route::get("products/calculate-rental-price", [ProductController::class, 'calculateRentalPrice']);
Route::post('import-products', [ProductController::class, 'importProducts']);
Route::post('import-variation-options', [ProductController::class, 'importVariationOptions']);
Route::get('export-products/{shop_id}', [ProductController::class, 'exportProducts']);
Route::get('export-variation-options/{shop_id}', [ProductController::class, 'exportVariableOptions']);
Route::post('generate-description', [ProductController::class, 'generateDescription']);
Route::post('import-attributes', [AttributeController::class, 'importAttributes']);
Route::get('export-attributes/{shop_id}', [AttributeController::class, 'exportAttributes']);
Route::get('download_url/token/{token}', [DownloadController::class, 'downloadFile'])->name('download_url.token');
Route::get('export-order/token/{token}', [OrderController::class, 'exportOrder'])->name('export_order.token');
Route::post('subscribe-to-newsletter', [UserController::class, 'subscribeToNewsletter'])->name('subscribeToNewsletter');
Route::get('download-invoice/token/{token}', [OrderController::class, 'downloadInvoice'])->name('download_invoice.token');
Route::post('webhooks/razorpay', [WebHookController::class, 'razorpay']);
Route::post('webhooks/stripe', [WebHookController::class, 'stripe']);
Route::post('webhooks/paypal', [WebHookController::class, 'paypal']);
Route::post('webhooks/mollie', [WebHookController::class, 'mollie']);
Route::post('webhooks/sslcommerz', [WebHookController::class, 'sslcommerz'])->name('sslc.sslcommerz');
Route::post('webhooks/paystack', [WebHookController::class, 'paystack']);
Route::post('webhooks/paymongo', [WebHookController::class, 'paymongo']);
Route::post('webhooks/xendit', [WebHookController::class, 'xendit']);
Route::post('webhooks/iyzico', [WebHookController::class, 'iyzico']);
Route::post('webhooks/bkash', [WebHookController::class, 'bkash']);
Route::post('webhooks/flutterwave', [WebHookController::class, 'flutterwave']);
// Courier (Shiprocket) shipment-status webhook — token-verified inside the controller
// (x-api-key), public (no auth:sanctum), throttled to blunt spoofing/retry storms.
Route::post('webhooks/shiprocket', [WebHookController::class, 'shiprocket'])->middleware('throttle:120,1');
// Borzo order/delivery callback — anti-spoof (only a signature-verified callback triggers the
// authenticated re-fetch), idempotent, never-5xx; tightly throttled. Public; verified in-controller.
Route::post('webhooks/borzo', [WebHookController::class, 'borzo'])->middleware('throttle:60,1');
// Dedicated shipping microservice → monolith callback (status/COD). Token-verified (x-api-key)
// in-controller, idempotent, never-5xx. Public route; throttled.
Route::post('shipping/callback', [WebHookController::class, 'shippingCallback'])->middleware('throttle:120,1');

// Voice Search — public: storefront reads the feature flag; the shop's server
// side posts query usage for cost tracking (guarded by an optional shared secret).
// The ingest endpoint is rate-limited to blunt cost-data poisoning at scale.
Route::get('voice-search/settings', [VoiceSearchController::class, 'getSettings']);
Route::post('voice-search/log', [VoiceSearchController::class, 'storeLog'])
    ->middleware('throttle:60,1');

// Plant Doctor — public: storefront reads the feature flag; the diagnose proxy
// forwards a photo/symptoms to the microservice. Rate-limited + budget-capped
// since each call incurs AI cost.
Route::get('plant-doctor/settings', [PlantDoctorController::class, 'getSettings']);
Route::post('plant-doctor/diagnose', [PlantDoctorController::class, 'diagnose'])
    ->middleware('throttle:30,1');

// Plant care tracker — public reads the feature flag; the customer routes (my plans,
// start a plan, mark reminders done) are auth-gated below. Plans are auto-created on
// delivery by the OrderDelivered listener.
Route::get('care-plans/settings', [CarePlanController::class, 'getSettings']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('care-plans', [CarePlanController::class, 'index']);
    Route::get('care-plans/{id}', [CarePlanController::class, 'show'])->whereNumber('id');
    Route::post('care-plans/generate', [CarePlanController::class, 'generate']);
    Route::post('care-plans/{id}/archive', [CarePlanController::class, 'archive'])->whereNumber('id');
    Route::post('care-plans/{id}/reminders/{reminderId}/done', [CarePlanController::class, 'markReminderDone']);
});

// Ask AI (per-plant chat) — public reads the feature flag; the chat itself runs
// on the async microservice (not here). `persist` is an internal callback from
// that service (X-Api-Key verified in-controller), called once per conversation.
Route::get('ai-chat/settings', [AiChatController::class, 'getSettings']);
Route::post('ai-chat/persist', [AiChatController::class, 'persist'])
    ->middleware('throttle:240,1');
// Native-app chat proxy → the async microservice (the web hits the service via its own
// edge proxy; the app has no server-side secret, so it forwards through Laravel like
// Plant Doctor). Auth-gated so the per-user daily cap has a real user_id; rate-limited.
Route::middleware(['auth:sanctum', 'throttle:20,1'])->group(function () {
    Route::post('ai-chat/ask', [AiChatController::class, 'ask']);
    Route::post('ai-chat/end', [AiChatController::class, 'end']);
});

// Garden service — public lead capture + active package templates for the page,
// and the Razorpay payment-link webhook. Lead capture is rate-limited per IP to
// stop form-spam from flooding the leads table.
Route::post('garden-leads', [GardenController::class, 'storeLead'])
    ->middleware('throttle:10,1');
Route::post('corporate-leads', [GardenController::class, 'storeLead'])
    ->middleware('throttle:10,1');
Route::get('garden-package-templates', [GardenController::class, 'templates']);
Route::post('webhooks/razorpay-garden', [GardenController::class, 'razorpayWebhook']);

// Delivery serviceability — public pincode check (storefront blocks unserviceable
// pincodes at checkout). Rate-limited.
Route::get('delivery-pincodes/check', [DeliveryPincodeController::class, 'check'])
    ->middleware('throttle:60,1');

// Master Location System (Phase 2) — public lookups for the State→City address
// dropdowns (storefront + admin). Read-only, rate-limited.
Route::get('locations/states', [LocationController::class, 'states'])->middleware('throttle:120,1');
Route::get('locations/cities', [LocationController::class, 'cities'])->middleware('throttle:120,1');

// Visitor / Live Activity NOC (Phase 3) — public, fire-and-forget storefront
// event ingest. Fail-safe (always 204); generous throttle for active browsing.
Route::post('track', [TrackingController::class, 'ingest'])->middleware('throttle:300,1');

// Garden service — logged-in customer: their packages + visit tracking + pay.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('my-garden-packages', [GardenController::class, 'myPackages']);
    Route::get('my-garden-packages/{id}', [GardenController::class, 'showMyPackage']);
    Route::post('garden-packages/{id}/pay', [GardenController::class, 'pay']);
    Route::post('gifting/checkout', [GardenController::class, 'giftingCheckout']);
});

Route::post('license-key/verify', [UserController::class, 'verifyLicenseKey']);

Route::get('callback/flutterwave', [WebHookController::class, 'callback'])->name('callback.flutterwave');

Route::get('near-by-shop/{lat}/{lng}', [ShopController::class, 'nearByShop']);

// Public: location-derived selling price + availability (margin over hidden vendor cost)
Route::get('location-price', [LocationPriceController::class, 'show']);
Route::post('location-price/batch', [LocationPriceController::class, 'batch'])->middleware('throttle:60,1');
Route::get('city-availability', [LocationPriceController::class, 'cityAvailability']);
Route::post('checkout/estimate', [LocationPriceController::class, 'checkoutEstimate']);

// Public: live courier position for an order (only while out for delivery)
Route::get('orders/{tracking}/courier-location', [OrderAssignmentController::class, 'courierLocation']);
Route::get('orders/{tracking}/shipments', [OrderAssignmentController::class, 'trackingShipments']);

Route::get('store-notices', [StoreNoticeController::class, 'index'])->name('store-notices.index');

Route::apiResource('products', ProductController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('types', TypeController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('attachments', AttachmentController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('categories', CategoryController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('delivery-times', DeliveryTimeController::class, [
    'only' => ['index', 'show']
]);
Route::apiResource('languages', LanguageController::class, [
    'only' => ['index', 'show']
]);
Route::apiResource('tags', TagController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('refund-reasons', RefundReasonController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('resources', ResourceController::class, [
    'only' => ['index', 'show']
]);
Route::apiResource('coupons', CouponController::class, [
    'only' => ['index', 'show'],
]);
Route::post('coupons/verify', [CouponController::class, 'verify']);
Route::apiResource('attributes', AttributeController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('shops', ShopController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('settings', SettingsController::class, [
    'only' => ['index'],
]);
Route::apiResource('reviews', ReviewController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('questions', QuestionController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('feedbacks', FeedbackController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('authors', AuthorController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('manufacturers', ManufacturerController::class, [
    'only' => ['index', 'show'],
]);
Route::post('orders/checkout/verify', [CheckoutController::class, 'verify']);
Route::apiResource('orders', OrderController::class, [
    'only' => ['show', 'store'],
]);

Route::post('/email/verification-notification', [UserController::class, 'sendVerificationEmail'])
    ->middleware(['auth:sanctum', 'throttle:6,1'])
    ->name('verification.send');

Route::post('orders/payment', [OrderController::class, 'submitPayment']);
Route::post('generate-descriptions', [AiController::class, 'generateDescription'])->middleware('throttle:10,1');
Route::get('/payment-intent', [PaymentIntentController::class, 'getPaymentIntent']);

Route::apiResource('faqs', FaqsController::class, [
    'only' => ['index', 'show'],
]);

Route::apiResource('terms-and-conditions', TermsAndConditionsController::class, [
    'only' => ['index', 'show'],
]);

Route::apiResource('flash-sale', FlashSaleController::class, [
    'only' => ['index', 'show'],
]);

Route::resource('refund-policies', RefundPolicyController::class, [
    'only' => ['index', 'show'],
]);


Route::post('shop-maintenance-event', [ShopController::class, 'shopMaintenanceEvent']);

/**
 * ******************************************
 * Authorized Route for Customers only
 * ******************************************
 */

Route::group(['middleware' => ['can:' . Permission::CUSTOMER, 'auth:sanctum', 'email.verified']], function () {
    Route::post('/update-email', [UserController::class, 'updateUserEmail']);
    Route::get('me', [UserController::class, 'me']);
    Route::apiResource('orders', OrderController::class, [
        'only' => ['index'],
    ]);
    Route::apiResource('reviews', ReviewController::class, [
        'only' => ['store', 'update']
    ]);
    Route::apiResource('questions', QuestionController::class, [
        'only' => ['store'],
    ]);
    Route::apiResource('feedbacks', FeedbackController::class, [
        'only' => ['store'],
    ]);
    Route::apiResource('abusive_reports', AbusiveReportController::class, [
        'only' => ['store'],
    ]);
    Route::apiResource('conversations', ConversationController::class, [
        'only' => ['index', 'store'],
    ]);
    Route::get('conversations/{conversation_id}', [ConversationController::class, 'show']);
    Route::get('messages/conversations/{conversation_id}', [MessageController::class, 'index']);
    Route::post('messages/conversations/{conversation_id}', [MessageController::class, 'store']);
    Route::post('messages/seen/{conversation_id}', [MessageController::class, 'seen']);
    Route::get('my-questions', [QuestionController::class, 'myQuestions']);
    Route::get('my-reports', [AbusiveReportController::class, 'myReports']);
    Route::post('wishlists/toggle', [WishlistController::class, 'toggle']);
    Route::apiResource('wishlists', WishlistController::class, [
        'only' => ['index', 'store', 'destroy'],
    ]);
    Route::get('wishlists/in_wishlist/{product_id}', [WishlistController::class, 'in_wishlist']);
    Route::get('my-wishlists', [ProductController::class, 'myWishlists']);
    Route::get('orders/tracking-number/{tracking_number}', 'Marvel\Http\Controllers\OrderController@findByTrackingNumber');
    Route::apiResource('attachments', AttachmentController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);

    Route::put('users/{id}', [UserController::class, 'update']);
    Route::post('/change-password', [UserController::class, 'changePassword']);
    Route::post('/update-contact', [UserController::class, 'updateContact']);
    Route::apiResource('address', AddressController::class, [
        'only' => ['destroy'],
    ]);
    Route::apiResource(
        'refunds',
        RefundController::class,
        [
            'only' => ['index', 'store', 'show'],
        ]
    );
    Route::get('downloads', [DownloadController::class, 'fetchDownloadableFiles']);
    Route::post('downloads/digital_file', [DownloadController::class, 'generateDownloadableUrl']);
    Route::get('/followed-shops-popular-products', [ShopController::class, 'followedShopsPopularProducts']);
    Route::get('/followed-shops', [ShopController::class, 'userFollowedShops']);
    Route::get('/follow-shop', [ShopController::class, 'userFollowedShop']);
    Route::post('/follow-shop', [ShopController::class, 'handleFollowShop']);
    Route::apiResource('cards', PaymentMethodController::class, [
        'only' => ['index', 'store', 'update', 'destroy'],
    ]);
    Route::post('/set-default-card', [PaymentMethodController::class, 'setDefaultCard']);
    Route::post('/save-payment-method', [PaymentMethodController::class, 'savePaymentMethod']);
    // Route::apiResource('faqs', FaqsController::class, [
    //     'only' => ['index', 'show'],
    // ]);
    Route::apiResource('notify-logs', NotifyLogsController::class, [
        'only' => ['index', 'show'],
    ]);
    Route::post('notify-log-seen', [NotifyLogsController::class, 'readNotifyLogs']);
    Route::post('notify-log-read-all', [NotifyLogsController::class, 'readAllNotifyLogs']);
});

/**
 * ******************************************
 * Authorized Route for Staff & Store Owner
 * ******************************************
 */

Route::group(
    ['middleware' => ['permission:' . Permission::STAFF . '|' . Permission::STORE_OWNER, 'auth:sanctum', 'email.verified']],
    function () {
        Route::apiResource('products', ProductController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);

        // PlantAtHome — product image (gallery) management
        Route::get('products/{id}/images', [ProductImageController::class, 'index']);
        Route::post('products/{id}/images', [ProductImageController::class, 'store']);
        Route::patch('products/{id}/images/reorder', [ProductImageController::class, 'reorder']);
        Route::patch('products/{id}/images/{image}/primary', [ProductImageController::class, 'setPrimary']);
        Route::patch('products/{id}/images/{image}/gallery', [ProductImageController::class, 'setGalleryFlag']);
        Route::delete('products/{id}/images/{image}', [ProductImageController::class, 'destroy']);
        Route::post('products/{id}/images/fetch', [ProductImageController::class, 'fetch']);
        // bulk + coverage report (non-products/{id} paths to avoid the show route shadow)
        Route::post('plant-images/fetch-missing', [ProductImageController::class, 'fetchMissing']);
        Route::get('plant-images/coverage-summary', [ProductImageController::class, 'coverageSummary']);
        Route::get('plant-images/coverage-report', [ProductImageController::class, 'coverageReport']);
        Route::get('plant-images/list', [ProductImageController::class, 'list']);

        Route::apiResource('resources', ResourceController::class, [
            'only' => ['store']
        ]);
        Route::apiResource('attributes', AttributeController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);
        Route::apiResource('attribute-values', AttributeValueController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);
        Route::apiResource('orders', OrderController::class, [
            'only' => ['update', 'destroy'],
        ]);
        // F3: pin/unpin an order (super-admin reaches this group via the
        // store_owner permission granted to the super_admin role).
        Route::post('orders/{id}/pin', [OrderController::class, 'pin']);

        // Route::get('shop-notification/{id}', [ShopNotificationController::class, 'show']);
        // Route::put('shop-notification/{id}', [ShopNotificationController::class, 'update']);
        // Route::get('popular-products', [AnalyticsController::class, 'popularProducts']);
        // Route::get('shops/refunds', 'Marvel\Http\Controllers\ShopController@refunds');
        Route::apiResource('questions', QuestionController::class, [
            'only' => ['update'],
        ]);
        Route::apiResource('authors', AuthorController::class, [
            'only' => ['store'],
        ]);
        Route::apiResource('manufacturers', ManufacturerController::class, [
            'only' => ['store'],
        ]);
        Route::get('store-notices/getStoreNoticeType', [StoreNoticeController::class, 'getStoreNoticeType']);
        Route::get('store-notices/getUsersToNotify', [StoreNoticeController::class, 'getUsersToNotify']);
        Route::post('store-notices/read/', [StoreNoticeController::class, 'readNotice']);
        Route::post('store-notices/read-all', [StoreNoticeController::class, 'readAllNotice']);
        Route::apiResource('store-notices', StoreNoticeController::class, [
            'only' => ['show', 'store', 'update', 'destroy']
        ]);

        Route::get('export-order-url/{shop_id?}', 'Marvel\Http\Controllers\OrderController@exportOrderUrl');
        Route::post('download-invoice-url', 'Marvel\Http\Controllers\OrderController@downloadInvoiceUrl');
        Route::apiResource('faqs', FaqsController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);
        Route::get('analytics', [AnalyticsController::class, 'analytics']);
        Route::get('low-stock-products', [AnalyticsController::class, 'lowStockProducts']);
        Route::get('category-wise-product', [AnalyticsController::class, 'categoryWiseProduct']);
        Route::get('category-wise-product-sale', [AnalyticsController::class, 'categoryWiseProductSale']);
        Route::get('draft-products', [ProductController::class, 'draftedProducts']);
        Route::get('products-stock', [ProductController::class, 'productStock']);
        Route::get('products-by-flash-sale', [FlashSaleController::class, 'getProductsByFlashSale']);
        Route::get('top-rate-product', [AnalyticsController::class, 'topRatedProducts']);
        Route::apiResource('coupons', CouponController::class, [
            'only' => ['update'],
        ]);
        // Route::get('products-requested-for-flash-sale-by-vendor', [FlashSaleVendorRequestController::class, 'getProductsByFlashSaleVendorRequest']);
        Route::get('requested-products-for-flash-sale', [FlashSaleVendorRequestController::class, 'getRequestedProductsForFlashSale']);
        Route::apiResource('vendor-requests-for-flash-sale', FlashSaleVendorRequestController::class, [
            'only' => ['index', 'show', 'store', 'destroy'],
        ]);
    }
);


/**
 * *****************************************
 * Authorized Route for Store owner Only
 * *****************************************
 */

Route::group(
    ['middleware' => ['permission:' . Permission::STORE_OWNER, 'auth:sanctum', 'email.verified']],
    function () {
        Route::apiResource('shops', ShopController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);
        // Route::get('analytics', [AnalyticsController::class, 'analytics']);
        Route::apiResource('withdraws', WithdrawController::class, [
            'only' => ['store', 'index', 'show'],
        ]);
        Route::post('staffs', [ShopController::class, 'addStaff']);
        Route::delete('staffs/{id}', [ShopController::class, 'deleteStaff']);
        Route::get('staffs', [UserController::class, 'staffs']);
        Route::get('my-shops', [ShopController::class, 'myShops']);
        Route::post('transfer-shop-ownership', [ShopController::class, 'transferShopOwnership']);

        // Vendor self-serve inventory (attach selling price + stock to MASTER products;
        // vendors never create products). Every write is forced to the caller's own shop.
        Route::get('vendor/catalog-search', [VendorInventoryController::class, 'catalogSearch']);
        Route::post('vendor/inventory/bulk-attach', [VendorInventoryController::class, 'bulkAttach']);
        Route::post('vendor/inventory/bulk-upload', [VendorInventoryController::class, 'bulkUpload']);
        Route::get('vendor/inventory/bulk-upload/{batch}/errors.csv', [VendorInventoryController::class, 'uploadErrors']);
        Route::get('vendor/inventory', [VendorInventoryController::class, 'inventory']);
        Route::patch('vendor/inventory/{id}', [VendorInventoryController::class, 'updateInventory']);
        Route::delete('vendor/inventory/{id}', [VendorInventoryController::class, 'deleteInventory']);
        Route::get('vendor/service-areas', [VendorInventoryController::class, 'serviceAreas']);
        Route::post('vendor/service-areas', [VendorInventoryController::class, 'addServiceArea']);
        Route::delete('vendor/service-areas/{id}', [VendorInventoryController::class, 'deleteServiceArea']);

        // Vendor ledger + settlements (own shop only; read-only earnings breakdown).
        Route::get('vendor/ledger', [SettlementController::class, 'myLedger']);
        Route::get('vendor/settlements', [SettlementController::class, 'mySettlements']);
        Route::get('vendor/balance', [SettlementController::class, 'myBalance']);
        Route::get('vendor/ledger.csv', [ReportController::class, 'myLedgerCsv']);
        Route::get('vendor/settlements.csv', [ReportController::class, 'mySettlementsCsv']);

        // Vendor dashboard widget (D2): low-stock alerts (own shop). Rejected/courier
        // orders reuse the standard `orders` endpoint (order_status / delivery_mode filter);
        // profit is folded into the `vendor/balance` summary.
        Route::get('vendor/low-stock', [VendorInventoryController::class, 'lowStock']);

        // Route::get('/admin/list', [UserController::class, 'admins']);
        // Route::apiResource('notify-logs', NotifyLogsController::class, [
        //     'only' => ['index'],
        // ]);

        // Route::post('notify-log-seen', [NotifyLogsController::class, 'readNotifyLogs']);
        // Route::post('notify-log-read-all', [NotifyLogsController::class, 'readAllNotifyLogs']);

        // Route::apiResource('faqs', FaqsController::class, [
        //     'only' => ['store', 'update', 'destroy'],
        // ]);

        Route::apiResource('flash-sale', FlashSaleController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);

        Route::get('product-flash-sale-info', [FlashSaleController::class, 'getFlashSaleInfoByProductID']);

        Route::apiResource('terms-and-conditions', TermsAndConditionsController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);

        Route::apiResource('coupons', CouponController::class, [
            'only' => ['store', 'destroy'],
        ]);

        Route::apiResource('terms-and-conditions', TermsAndConditionsController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);
        Route::get('/vendors/list', [UserController::class, 'vendors']);
        // Route::post('products-request-for-flash-sale', [FlashSaleVendorRequestController::class, 'productsRequestForFlashSale']);

        Route::apiResource('ownership-transfer', OwnershipTransferController::class, [
            'only' => ['index', 'show'],
        ]);
    }
);

/**
 * *****************************************
 * Authorized Route for Super Admin only
 * *****************************************
 */

Route::group(['middleware' => ['permission:' . Permission::SUPER_ADMIN, 'auth:sanctum']], function () {
    // Courier / shipping-microservice config: master switch + default package + per-partner
    // (Borzo / Shiprocket / Go shipping-service) enable + non-secret settings + ENCRYPTED
    // credentials. GET returns masked status (never secret values); POST encrypt-saves.
    Route::get('courier-settings', [CourierConfigController::class, 'index']);
    Route::post('courier-settings', [CourierConfigController::class, 'update']);
    // Route::get('messages/get-conversations/{shop_id}', [ConversationController::class, 'getConversationByShopId']);
    // Route::get('analytics', [AnalyticsController::class, 'analytics']);
    Route::apiResource('types', TypeController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    Route::apiResource('withdraws', WithdrawController::class, [
        'only' => ['update', 'destroy'],
    ]);

    // Delivery partners — onboarding + management (KYC, location, commission)
    Route::apiResource('delivery-partners', DeliveryPartnerController::class, [
        'only' => ['index', 'show', 'store', 'update', 'destroy'],
    ]);
    Route::post('approve-delivery-partner', [DeliveryPartnerController::class, 'approve']);

    // Vendor price sheets (admin-only Excel cost upload + audit + review)
    Route::post('import-vendor-price-sheet', [PriceSheetController::class, 'import']);
    Route::get('price-import-batches', [PriceSheetController::class, 'batches']);
    Route::get('vendor-product-prices', [PriceSheetController::class, 'prices']);
    Route::delete('vendor-product-prices/{id}', [PriceSheetController::class, 'destroy']);
    Route::get('catalog-product-vendors/{productId}', [PriceSheetController::class, 'productVendors']);

    // Vendor ledger + T+N settlement (admin: every vendor; run + pay)
    Route::get('vendor-ledger', [SettlementController::class, 'ledger']);
    Route::get('settlements', [SettlementController::class, 'settlements']);
    Route::get('settlements/reconciliation', [SettlementController::class, 'reconciliation']);
    Route::get('settlements/{id}', [SettlementController::class, 'showSettlement']);
    Route::post('settlements/run', [SettlementController::class, 'runSettlements']);
    Route::post('settlements/{id}/pay', [SettlementController::class, 'paySettlement']);

    // Financial reports + exports (F4): CSV primary, PDF statement optional.
    Route::get('reports/ledger.csv', [ReportController::class, 'exportLedger']);
    Route::get('reports/settlements.csv', [ReportController::class, 'exportSettlements']);
    Route::get('reports/commission.csv', [ReportController::class, 'exportCommission']);
    Route::get('reports/gst.csv', [ReportController::class, 'exportGst']);
    Route::get('reports/cod-reconciliation', [ReportController::class, 'codReconciliation']);
    Route::get('settlements/{id}/statement.pdf', [ReportController::class, 'settlementStatementPdf']);

    // Courier (Shiprocket) operations on a shipment (C3): book / label / pickup / track,
    // and register a vendor pickup location. Inert until courier is configured + enabled.
    Route::post('shipments/{id}/book-courier', [CourierShipmentController::class, 'book']);
    Route::post('shipments/{id}/generate-label', [CourierShipmentController::class, 'label']);
    Route::post('shipments/{id}/schedule-pickup', [CourierShipmentController::class, 'pickup']);
    Route::get('shipments/{id}/courier-track', [CourierShipmentController::class, 'track']);
    Route::post('shops/{id}/sync-pickup', [CourierShipmentController::class, 'syncPickup']);
    // Multi-partner shipping (Shiprocket + Borzo): ranked quotes, mode-routed dispatch, cancel.
    Route::get('shipments/{id}/shipping-quotes', [CourierShipmentController::class, 'quotes']);
    Route::post('shipments/{id}/dispatch', [CourierShipmentController::class, 'dispatch']);
    Route::post('shipments/{id}/cancel-shipment', [CourierShipmentController::class, 'cancelShipment']);

    // Marketplace analytics widgets (admin dashboard, D1).
    Route::get('analytics/city-sales', [AnalyticsController::class, 'cityWiseSales']);
    Route::get('analytics/top-vendors', [AnalyticsController::class, 'topVendors']);
    Route::get('analytics/vendor-profitability', [AnalyticsController::class, 'vendorProfitability']);
    Route::get('analytics/pending-fulfillments', [AnalyticsController::class, 'pendingFulfillments']);
    Route::get('analytics/courier-orders', [AnalyticsController::class, 'courierOrders']);
    Route::get('top-selling-product', [AnalyticsController::class, 'topSellingProducts']);

    // Operations Command Center (Phase 1) — consumed by the live dashboard (polled).
    Route::get('command-center/overview', [CommandCenterController::class, 'overview']);
    Route::get('command-center/live-operations', [CommandCenterController::class, 'liveOperations']);
    Route::get('command-center/city-health', [CommandCenterController::class, 'cityHealth']);
    Route::get('command-center/delivery-ops', [CommandCenterController::class, 'deliveryOps']);
    Route::get('command-center/courier-positions', [CommandCenterController::class, 'courierPositions']);
    Route::get('command-center/city-dashboard', [CommandCenterController::class, 'cityDashboard']);
    // Phase 3 — Visitor / Live Activity NOC reads.
    Route::get('command-center/live-visitors', [CommandCenterController::class, 'liveVisitors']);
    Route::get('command-center/visitor-journey', [CommandCenterController::class, 'visitorJourney']);
    Route::get('command-center/funnel', [CommandCenterController::class, 'funnel']);
    Route::get('command-center/activity-feed', [CommandCenterController::class, 'activityFeed']);
    // Phase 4 — Inventory + Customer Intelligence.
    Route::get('command-center/inventory', [CommandCenterController::class, 'inventory']);
    Route::get('command-center/customer-intelligence', [CommandCenterController::class, 'customerIntelligence']);

    // Order → vendor + delivery-partner matching/assignment (P3)
    Route::get('orders/{id}/match', [OrderAssignmentController::class, 'match']);
    Route::get('orders/{id}/fulfillment-plan', [OrderAssignmentController::class, 'fulfillmentPlan']);
    Route::get('orders/{id}/item-assignment-plan', [OrderAssignmentController::class, 'itemAssignmentPlan']);
    // P4: persisted per-item assignment + shipments (single customer order)
    Route::get('orders/{id}/items', [OrderAssignmentController::class, 'items']);
    Route::post('orders/{id}/auto-assign-items', [OrderAssignmentController::class, 'autoAssignItems']);
    Route::post('orders/{id}/assign-items', [OrderAssignmentController::class, 'assignItems']);
    Route::post('orders/{id}/assign', [OrderAssignmentController::class, 'assign']);

    // Delivery-partner payouts + profit (P4)
    Route::get('delivery-partner-withdraws', [DeliveryPartnerWithdrawController::class, 'index']);
    Route::post('delivery-partner-withdraws', [DeliveryPartnerWithdrawController::class, 'store']);
    Route::post('approve-delivery-partner-withdraw', [DeliveryPartnerWithdrawController::class, 'approve']);
    Route::get('vendor-dp-profit', [OrderAssignmentController::class, 'profit']);
    // DP earnings analytics (admin view) + manual incentive
    Route::get('delivery-partner-earnings', [DeliveryPartnerEarningsController::class, 'partnerEarnings']);
    Route::post('delivery-partner-incentive', [DeliveryPartnerEarningsController::class, 'grantIncentive']);
    Route::get('delivery-partners/{id}/location', [DeliveryPartnerController::class, 'partnerLocation']);
    Route::apiResource('categories', CategoryController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    Route::apiResource('delivery-times', DeliveryTimeController::class, [
        'only' => ['store', 'update', 'destroy']
    ]);
    Route::apiResource('languages', LanguageController::class, [
        'only' => ['store', 'update', 'destroy']
    ]);
    Route::apiResource('tags', TagController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    Route::apiResource('refund-reasons', RefundReasonController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    Route::apiResource('resources', ResourceController::class, [
        'only' => ['update', 'destroy']
    ]);
    // Route::apiResource('coupons', CouponController::class, [
    //     'only' => ['store', 'update', 'destroy'],
    // ]);
    // Route::apiResource('order-status', OrderStatusController::class, [
    //     'only' => ['store', 'update', 'destroy'],
    // ]);
    Route::apiResource('reviews', ReviewController::class, [
        'only' => ['destroy']
    ]);
    Route::apiResource('questions', QuestionController::class, [
        'only' => ['destroy'],
    ]);
    Route::apiResource('feedbacks', FeedbackController::class, [
        'only' => ['update', 'destroy'],
    ]);
    Route::apiResource('abusive_reports', AbusiveReportController::class, [
        'only' => ['index', 'show', 'update', 'destroy'],
    ]);
    Route::post('abusive_reports/accept', [AbusiveReportController::class, 'accept']);
    Route::post('abusive_reports/reject', [AbusiveReportController::class, 'reject']);
    Route::apiResource('settings', SettingsController::class, [
        'only' => ['store'],
    ]);
    Route::apiResource('users', UserController::class);
    Route::apiResource('authors', AuthorController::class, [
        'only' => ['update', 'destroy'],
    ]);
    Route::apiResource('manufacturers', ManufacturerController::class, [
        'only' => ['update', 'destroy'],
    ]);
    Route::post('users/block-user', [UserController::class, 'banUser']);
    Route::post('users/unblock-user', [UserController::class, 'activeUser']);
    Route::apiResource('taxes', TaxController::class);
    Route::apiResource('shippings', ShippingController::class);
    Route::post('approve-shop', [ShopController::class, 'approveShop']);
    Route::post('disapprove-shop', [ShopController::class, 'disApproveShop']);
    Route::post('approve-withdraw', [WithdrawController::class, 'approveWithdraw']);
    Route::post('add-points', [UserController::class, 'addPoints']);
    Route::post('users/make-admin', [UserController::class, 'makeOrRevokeAdmin']);
    Route::apiResource(
        'refunds',
        RefundController::class,
        [
            'only' => ['destroy', 'update'],
        ]
    );
    Route::apiResource('notify-logs', NotifyLogsController::class, [
        'only' => ['destroy'],
    ]);
    // Route::apiResource('faqs', FaqsController::class, [
    //     'only' => ['store', 'update', 'destroy'],
    // ]);
    Route::get('new-shops', [ShopController::class, 'newOrInActiveShops']);
    Route::post('approve-terms-and-conditions', [TermsAndConditionsController::class, 'approveTerm']);
    Route::post('disapprove-terms-and-conditions', [TermsAndConditionsController::class, 'disApproveTerm']);
    Route::get('/admin/list', [UserController::class, 'admins']);

    Route::get('/customers/list', [UserController::class, 'customers']);
    Route::get('my-staffs', [UserController::class, 'myStaffs']);
    Route::get('all-staffs', [UserController::class, 'allStaffs']);
    Route::resource('refund-policies', RefundPolicyController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    Route::post('approve-coupon', [CouponController::class, 'approveCoupon']);
    Route::post('disapprove-coupon', [CouponController::class, 'disApproveCoupon']);
    // Route::get('requested-products-for-flash-sale', [FlashSaleVendorRequestController::class, 'getRequestedProductsForFlashSale']);
    Route::post('approve-flash-sale-requested-products', [FlashSaleVendorRequestController::class, 'approveFlashSaleProductsRequest']);
    Route::post('disapprove-flash-sale-requested-products', [FlashSaleVendorRequestController::class, 'disapproveFlashSaleProductsRequest']);
    Route::apiResource('vendor-requests-for-flash-sale', FlashSaleVendorRequestController::class, [
        'only' => ['update'],
    ]);
    Route::apiResource('ownership-transfer', OwnershipTransferController::class, [
        'only' => ['update', 'destroy'],
    ]);

    // Voice Search — admin writes/stats/logs (this group already enforces
    // permission:SUPER_ADMIN + auth:sanctum). The public read + ingest routes
    // live in the public section above.
    Route::post('voice-search/settings', [VoiceSearchController::class, 'updateSettings']);
    Route::get('voice-search/stats', [VoiceSearchController::class, 'getStats']);
    Route::get('voice-search/logs', [VoiceSearchController::class, 'getLogs']);

    // Plant Doctor — admin management (settings/stats/logs).
    Route::get('plant-doctor/admin-settings', [PlantDoctorController::class, 'getAdminSettings']);
    Route::post('plant-doctor/settings', [PlantDoctorController::class, 'updateSettings']);
    Route::get('plant-doctor/stats', [PlantDoctorController::class, 'getStats']);
    Route::get('plant-doctor/logs', [PlantDoctorController::class, 'getLogs']);

    // Care plans — admin management (settings + month cost).
    Route::get('care-plans/admin-settings', [CarePlanController::class, 'getAdminSettings']);
    Route::post('care-plans/settings', [CarePlanController::class, 'updateSettings']);

    // Ask AI — admin management (settings/stats) + transcript browsing by user.
    Route::get('ai-chat/admin-settings', [AiChatController::class, 'getAdminSettings']);
    Route::post('ai-chat/settings', [AiChatController::class, 'updateSettings']);
    Route::get('ai-chat/stats', [AiChatController::class, 'getStats']);
    Route::get('ai-chat/conversations', [AiChatController::class, 'getConversations']);
    Route::get('ai-chat/conversations/{id}', [AiChatController::class, 'getConversation']);

    // Delivery serviceability — admin management (allow-list of pincodes)
    Route::get('delivery-pincodes', [DeliveryPincodeController::class, 'index']);
    Route::post('delivery-pincodes/bulk', [DeliveryPincodeController::class, 'bulkStore']);
    Route::post('delivery-pincodes', [DeliveryPincodeController::class, 'store']);
    Route::put('delivery-pincodes/{id}', [DeliveryPincodeController::class, 'update']);
    Route::delete('delivery-pincodes/{id}', [DeliveryPincodeController::class, 'destroy']);

    // Master Location System (Phase 2) — states / cities / warehouses + the
    // City Activation Engine (super-admin only).
    Route::get('states', [LocationController::class, 'stateIndex']);
    Route::post('states', [LocationController::class, 'stateStore']);
    Route::put('states/{id}', [LocationController::class, 'stateUpdate']);
    Route::delete('states/{id}', [LocationController::class, 'stateDestroy']);

    Route::get('cities', [LocationController::class, 'cityIndex']);
    Route::get('cities/{id}', [LocationController::class, 'cityShow']);
    Route::post('cities', [LocationController::class, 'cityStore']);
    Route::put('cities/{id}', [LocationController::class, 'cityUpdate']);
    Route::post('cities/{id}/status', [LocationController::class, 'citySetStatus']);
    Route::delete('cities/{id}', [LocationController::class, 'cityDestroy']);

    Route::get('warehouses', [LocationController::class, 'warehouseIndex']);
    Route::post('warehouses', [LocationController::class, 'warehouseStore']);
    Route::put('warehouses/{id}', [LocationController::class, 'warehouseUpdate']);
    Route::delete('warehouses/{id}', [LocationController::class, 'warehouseDestroy']);

    // Garden service — admin management
    Route::get('garden-leads', [GardenController::class, 'leads']);
    Route::put('garden-leads/{id}', [GardenController::class, 'updateLead']);
    Route::get('garden-templates', [GardenController::class, 'allTemplates']);
    Route::post('garden-templates', [GardenController::class, 'storeTemplate']);
    Route::put('garden-templates/{id}', [GardenController::class, 'updateTemplate']);
    Route::delete('garden-templates/{id}', [GardenController::class, 'destroyTemplate']);
    Route::get('garden-packages', [GardenController::class, 'packages']);
    Route::get('garden-packages/{id}', [GardenController::class, 'showPackage']);
    Route::post('garden-packages', [GardenController::class, 'storePackage']);
    Route::put('garden-packages/{id}', [GardenController::class, 'updatePackage']);
    Route::post('garden-packages/{id}/payment-link', [GardenController::class, 'createPaymentLink']);
    Route::post('garden-packages/{id}/visits', [GardenController::class, 'addVisit']);
    Route::put('garden-package-visits/{visitId}', [GardenController::class, 'updateVisit']);

    // System — pending tasks checklist + request/response logging viewer
    Route::get('admin-tasks', [SystemController::class, 'tasks']);
    Route::post('admin-tasks', [SystemController::class, 'storeTask']);
    Route::put('admin-tasks/{id}', [SystemController::class, 'updateTask']);
    Route::delete('admin-tasks/{id}', [SystemController::class, 'destroyTask']);
    Route::get('request-logs', [SystemController::class, 'logs']);
    Route::get('request-logs/settings', [SystemController::class, 'logSettings']);
    Route::post('request-logs/settings', [SystemController::class, 'updateLogSettings']);
    Route::post('request-logs/clear', [SystemController::class, 'clearLogs']);
});


// ── F4: Employees + module-based role permissions ────────────────────────────
// Module-permission-gated (NOT super_admin-only) so the new `admin` role can
// manage employees/roles too. Super-admin passes every check via the existing
// Gate::before bypass; manager/staff/viewer are blocked (no employees.* perms).
Route::group(['middleware' => ['auth:sanctum', 'email.verified']], function () {
    Route::get('employees', [UserController::class, 'employees'])
        ->middleware('permission:employees.view');
    Route::post('employees', [UserController::class, 'storeEmployee'])
        ->middleware('permission:employees.create');
    Route::post('users/{id}/assign-role', [UserController::class, 'assignRole'])
        ->middleware('permission:employees.edit');

    Route::get('acl/roles', [RolePermissionController::class, 'roles'])
        ->middleware('permission:employees.view');
    Route::get('acl/permissions', [RolePermissionController::class, 'permissions'])
        ->middleware('permission:employees.view');
    Route::put('acl/roles/{id}/permissions', [RolePermissionController::class, 'updateRolePermissions'])
        ->middleware('permission:employees.edit');
});


// ── Bundle Management System (admin-only; bundle = product_type='bundle') ─────
// Module-permission gated (bundles.*); super-admin passes via Gate::before.
// Static-segment routes are declared BEFORE bundles/{id} so they aren't captured.
Route::group(['middleware' => ['auth:sanctum', 'email.verified']], function () {
    Route::get('bundles', [BundleController::class, 'index'])
        ->middleware('permission:bundles.view');
    Route::get('bundles/eligible-plants', [BundleController::class, 'eligiblePlants'])
        ->middleware('permission:bundles.view');
    Route::get('bundles/analytics', [BundleController::class, 'analytics'])
        ->middleware('permission:bundles.view');
    Route::post('bundles/preview', [BundleController::class, 'preview'])
        ->middleware('permission:bundles.view');
    Route::post('bundles/generate', [BundleController::class, 'generate'])
        ->middleware('permission:bundles.create');
    Route::get('bundles/{id}', [BundleController::class, 'show'])
        ->middleware('permission:bundles.view');
    Route::post('bundles', [BundleController::class, 'store'])
        ->middleware('permission:bundles.create');
    Route::put('bundles/{id}', [BundleController::class, 'update'])
        ->middleware('permission:bundles.edit');
    Route::post('bundles/{id}/toggle', [BundleController::class, 'toggle'])
        ->middleware('permission:bundles.edit');
    Route::post('bundles/{id}/duplicate', [BundleController::class, 'duplicate'])
        ->middleware('permission:bundles.create');
    Route::delete('bundles/{id}', [BundleController::class, 'destroy'])
        ->middleware('permission:bundles.delete');
});


// Delivery-partner self routes (their own dashboard).
Route::group(['middleware' => ['permission:delivery_partner', 'auth:sanctum'], 'prefix' => 'dp'], function () {
    Route::get('me', [DeliveryPartnerController::class, 'me']);
    Route::get('orders', [DeliveryPartnerController::class, 'myOrders']);
    Route::post('orders/{id}/status', [DeliveryPartnerController::class, 'updateMyOrderStatus']);
    Route::post('location', [DeliveryPartnerController::class, 'postLocation']);
    Route::get('earnings', [DeliveryPartnerEarningsController::class, 'myEarnings']);
    Route::get('withdraws', [DeliveryPartnerWithdrawController::class, 'index']);
    Route::post('withdraws', [DeliveryPartnerWithdrawController::class, 'store']);
});
