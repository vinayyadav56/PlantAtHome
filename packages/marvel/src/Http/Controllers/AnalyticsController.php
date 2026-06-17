<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\User;
use Marvel\Database\Repositories\AddressRepository;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Spatie\Permission\Models\Permission as ModelsPermission;

class AnalyticsController extends CoreController
{
    public $addressRepository;

    public function __construct(AddressRepository $addressRepository)
    {
        $this->addressRepository = $addressRepository;
    }


    public function analytics(Request $request)
    {
        try {
            $user = $request->user();
            // if (!$user || !$user->hasPermissionTo(Permission::STORE_OWNER)) {
            //     throw new AuthenticationException();
            // }
            $shops = $user?->shops->pluck('id') ?? [];

            // Total revenue
            $totalRevenueQuery = DB::table('orders as childOrder')
                ->whereDate('childOrder.created_at', '<=', Carbon::now())
                ->where('childOrder.order_status', OrderStatus::COMPLETED)
                ->whereNotNull('childOrder.parent_id')
                ->join('orders as parentOrder', 'childOrder.parent_id', '=', 'parentOrder.id')
                ->whereDate('parentOrder.created_at', '<=', Carbon::now())
                ->where('parentOrder.order_status', OrderStatus::COMPLETED)
                ->select(
                    'childOrder.id',
                    'childOrder.parent_id',
                    'childOrder.paid_total',
                    'childOrder.created_at',
                    'childOrder.shop_id',
                    'parentOrder.delivery_fee',
                    'parentOrder.sales_tax',
                );

            // Revenue section : Total
            if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
                $totalRevenueQuery = $totalRevenueQuery->get();
                $totalRevenue = $totalRevenueQuery->sum('paid_total')
                    + $totalRevenueQuery->unique('parent_id')->sum('delivery_fee')
                    + $totalRevenueQuery->unique('parent_id')->sum('sales_tax');
            } else {
                $totalRevenue = $totalRevenueQuery
                    ->whereIn('childOrder.shop_id', $shops)
                    ->get()
                    ->sum('paid_total');
            }

            // Today's revenue
            $todaysRevenueQuery =  DB::table('orders as A')
                ->whereDate('A.created_at', '>', Carbon::now()->subDays(1))
                ->where('A.order_status', OrderStatus::COMPLETED)
                ->where('A.parent_id', '!=', null)
                ->join('orders as B', 'A.parent_id', '=', 'B.id')
                ->where('B.order_status', OrderStatus::COMPLETED)
                ->select(
                    'A.id',
                    'A.parent_id',
                    'A.paid_total',
                    'B.delivery_fee',
                    'B.sales_tax',
                    'A.created_at',
                    'A.shop_id'
                );

            if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
                $todaysRevenueQuery = $todaysRevenueQuery->get();
                $todaysRevenue =  $todaysRevenueQuery->sum('paid_total') +
                    $todaysRevenueQuery->unique('parent_id')->sum('delivery_fee') +
                    $todaysRevenueQuery->unique('parent_id')->sum('sales_tax');
            } else {
                $todaysRevenue = $todaysRevenueQuery->whereIn('A.shop_id', $shops)->get()->sum('paid_total');
            }

            // total refunds
            $totalRefundQuery = DB::table('refunds')->whereDate('created_at', '<', Carbon::now());
            if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
                $totalRefunds = $totalRefundQuery->where('shop_id', null)->sum('amount');
            } else {
                $totalRefunds = $totalRefundQuery->whereIn('shop_id', $shops)->sum('amount');
            }

            // total orders
            $totalOrdersQuery = DB::table('orders')->whereDate('created_at', '<=', Carbon::now());
            if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
                $totalOrders = $totalOrdersQuery->where('parent_id', null)->count();
            } else {
                $totalOrders = $totalOrdersQuery->whereIn('shop_id', $shops)->count();
            }

            // total shops
            if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
                $totalVendors = User::whereHas('permissions', function ($query) {
                    $query->where('name', Permission::STORE_OWNER);
                })->count();
                $totalShops = Shop::count();
            } else {
                $totalShops = Shop::where('owner_id', '=', $user->id)->count();
            }

            $newCustomers = User::permission(Permission::CUSTOMER)->whereDate('created_at', '>', Carbon::now()->subDays(30))->count();

            $verticalAnalytics = $this->getVerticalAnalytics($user);

            $totalYearSaleByMonth = $this->getTotalYearSaleByMonth($user);
            $todayTotalOrderByStatus = $this->orderCountingByStatus($request, 1);
            $weeklyDaysTotalOrderByStatus = $this->orderCountingByStatus($request, 7);
            $monthlyTotalOrderByStatus = $this->orderCountingByStatus($request, 30);
            $yearlyTotalOrderByStatus = $this->orderCountingByStatus($request, 365);


            return [
                'totalRevenue'              => $totalRevenue ?? 0,
                'totalRefunds'              => $totalRefunds ?? 0,
                'totalShops'                => $totalShops,
                'totalVendors'              => $totalVendors ?? 0,
                'todaysRevenue'             => $todaysRevenue,
                'totalOrders'               => $totalOrders,
                'newCustomers'              => $newCustomers,
                'verticalAnalytics'         => $verticalAnalytics,
                'totalYearSaleByMonth'      => $totalYearSaleByMonth,
                'todayTotalOrderByStatus'   => $todayTotalOrderByStatus,
                'weeklyTotalOrderByStatus'  => $weeklyDaysTotalOrderByStatus,
                'monthlyTotalOrderByStatus' => $monthlyTotalOrderByStatus,
                'yearlyTotalOrderByStatus'  => $yearlyTotalOrderByStatus,
            ];
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }

    public function getTotalYearSaleByMonth(User $user)
    {
        $months = [
            "January", "February", "March", "April", "May", "June",
            "July", "August", "September", "October", "November", "December"
        ];

        $query = DB::table('orders as A')
            ->where('A.order_status', OrderStatus::COMPLETED)
            ->whereYear('A.created_at', Carbon::now()->year);

        if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            $query->whereNull('A.parent_id')
                ->join('orders as B', 'A.id', '=', 'B.parent_id')
                ->where('B.order_status', OrderStatus::COMPLETED)
                ->select(
                    DB::raw("SUM(A.paid_total) as total"),
                    DB::raw("DATE_FORMAT(A.created_at, '%M') as month")
                );
        } else {
            $shops = $user ? $user->shops->pluck('id') : [];
            $query->whereNotNull('A.parent_id')
                ->join('orders as B', 'A.parent_id', '=', 'B.id')
                ->whereIn('A.shop_id', $shops)
                ->select(
                    DB::raw("SUM(B.amount) as total"),
                    DB::raw("DATE_FORMAT(A.created_at, '%M') as month")
                );
        }

        $totalYearSaleByMonth = $query->groupBy('month')->pluck('total', 'month')->toArray();

        return array_map(
            fn ($month) =>
            [
                'month' => $month,
                'total' => $totalYearSaleByMonth[$month] ?? 0
            ],
            $months
        );
    }

    /**
     * Per-vertical analytics (Plants / Tools / FarmBox).
     * Revenue + order count + AOV from COMPLETED suborders grouped by vertical_type_id,
     * plus catalog size + low-stock count per vertical. (Architecture P6.)
     */
    public function getVerticalAnalytics(?User $user)
    {
        $language = DEFAULT_LANGUAGE;
        $isSuper  = $user && $user->hasPermissionTo(Permission::SUPER_ADMIN);
        $shops    = $user?->shops->pluck('id') ?? [];

        // verticals = product Types (one row per vertical, even with zero sales)
        $types = Type::where('language', $language)->get(['id', 'name', 'slug']);

        // revenue + order count per vertical from completed suborders (linked to a completed parent)
        $salesQuery = DB::table('orders as child')
            ->join('orders as parent', 'child.parent_id', '=', 'parent.id')
            ->whereNotNull('child.vertical_type_id')
            ->where('child.order_status', OrderStatus::COMPLETED)
            ->where('parent.order_status', OrderStatus::COMPLETED);
        if (!$isSuper) {
            $salesQuery->whereIn('child.shop_id', $shops);
        }
        $sales = $salesQuery
            ->select(
                'child.vertical_type_id as type_id',
                DB::raw('COUNT(child.id) as orders'),
                DB::raw('SUM(child.paid_total) as revenue')
            )
            ->groupBy('child.vertical_type_id')
            ->get()
            ->keyBy('type_id');

        // catalog size + low-stock (< 10) per vertical
        $productBase = DB::table('products')->where('language', $language)->whereNotNull('type_id');
        if (!$isSuper) {
            $productBase->whereIn('shop_id', $shops);
        }
        $productCounts = (clone $productBase)
            ->select('type_id', DB::raw('COUNT(*) as c'))->groupBy('type_id')->pluck('c', 'type_id');
        $lowStock = (clone $productBase)->where('quantity', '<', 10)
            ->select('type_id', DB::raw('COUNT(*) as c'))->groupBy('type_id')->pluck('c', 'type_id');

        return $types->map(function ($t) use ($sales, $lowStock, $productCounts) {
            $row     = $sales[$t->id] ?? null;
            $orders  = (int) ($row->orders ?? 0);
            $revenue = (float) ($row->revenue ?? 0);
            return [
                'type_id'       => $t->id,
                'name'          => $t->name,
                'slug'          => $t->slug,
                'revenue'       => $revenue,
                'orders'        => $orders,
                'aov'           => $orders > 0 ? round($revenue / $orders, 2) : 0,
                'totalProducts' => (int) ($productCounts[$t->id] ?? 0),
                'lowStock'      => (int) ($lowStock[$t->id] ?? 0),
            ];
        })->values();
    }

    public function orderCountingByStatus($request, int $days = 1)
    {
        $user = $request->user();

        switch ($user) {
            case $user->hasPermissionTo(Permission::SUPER_ADMIN):
                $query =  DB::table('orders as A')
                    ->where('A.parent_id', '=', null)
                    ->whereDate('A.created_at', '>', Carbon::now()->subDays($days))
                    ->select(
                        'A.order_status',
                        DB::raw('count(*) as order_count')
                    )
                    ->groupBy('A.order_status')
                    ->pluck('order_count', 'order_status');
                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):
                $shops = $user?->shops->pluck('id') ?? [];
                $query =  DB::table('orders as A')
                    ->where('A.parent_id', '!=', null)
                    ->whereDate('A.created_at', '>', Carbon::now()->subDays($days))
                    ->whereIn('A.shop_id', $shops)
                    ->select(
                        'A.order_status',
                        DB::raw('count(*) as order_count')
                    )
                    ->groupBy('A.order_status')
                    ->pluck('order_count', 'order_status');
                break;

            case $user->hasPermissionTo(Permission::STAFF):
                $shop = $user?->shop_id ?? [];
                $query =  DB::table('orders as A')
                    ->where('A.parent_id', '!=', null)
                    ->whereDate('A.created_at', '>', Carbon::now()->subDays($days))
                    ->where('A.shop_id', '=', $shop)
                    ->select(
                        'A.order_status',
                        DB::raw('count(*) as order_count')
                    )
                    ->groupBy('A.order_status')
                    ->pluck('order_count', 'order_status');
                break;
        }

        // if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
        //     // for super-admin
        //     $query =  DB::table('orders as A')
        //         ->where('A.parent_id', '=', null)
        //         ->whereDate('A.created_at', '>', Carbon::now()->subDays($days))
        //         ->select(
        //             'A.order_status',
        //             DB::raw('count(*) as order_count')
        //         )
        //         ->groupBy('A.order_status')
        //         ->pluck('order_count', 'order_status');
        // } else {
        //     // for vendor
        //     $shops = $user?->shops->pluck('id') ?? [];
        //     $query =  DB::table('orders as A')
        //         ->where('A.parent_id', '!=', null)
        //         ->whereDate('A.created_at', '>', Carbon::now()->subDays($days))
        //         ->whereIn('A.shop_id', $shops)
        //         ->select(
        //             'A.order_status',
        //             DB::raw('count(*) as order_count')
        //         )
        //         ->groupBy('A.order_status')
        //         ->pluck('order_count', 'order_status');
        // }


        return [
            'pending'        => $query[OrderStatus::PENDING]           ?? 0,
            'processing'     => $query[OrderStatus::PROCESSING]        ?? 0,
            'complete'       => $query[OrderStatus::COMPLETED]         ?? 0,
            'cancelled'      => $query[OrderStatus::CANCELLED]         ?? 0,
            'refunded'       => $query[OrderStatus::REFUNDED]          ?? 0,
            'failed'         => $query[OrderStatus::FAILED]            ?? 0,
            'localFacility'  => $query[OrderStatus::AT_LOCAL_FACILITY] ?? 0,
            'outForDelivery' => $query[OrderStatus::OUT_FOR_DELIVERY]  ?? 0,
        ];
    }

    /**
     * lowStockProducts
     *
     * @param  Request $request
     * @return object
     */
    public function lowStockProducts(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        return $this->lowStockProductsWithPagination($request)->take($limit)->get();
    }

    /**
     * lowStockProducts
     *
     * @param  Request $request
     * @return object
     */
    public function lowStockProductsWithPagination(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;

        // product group type
        $type_id = $request->type_id ? $request->type_id : '';
        if (isset($request->type_slug) && empty($type_id)) {
            try {
                $type = Type::where('slug', $request->type_slug)->where('language', $language)->firstOrFail();
                $type_id = $type->id;
            } catch (MarvelException $e) {
                throw new MarvelException(NOT_FOUND);
            }
        }
        $products_query = Product::with(['type', 'shop'])->where('language', $language)->where('quantity', '<', 10);

        // fetched by shop_id
        if (isset($request->shop_id)) {
            $products_query = $products_query->where('shop_id', "=", $request->shop_id);
        }

        // fetched type
        if ($type_id) {
            $products_query = $products_query->where('type_id', '=', $type_id);
        }
        return $products_query;
    }

    /**
     * categoryWiseProduct
     *
     * @param  Request $request
     * @return void
     */
    public function categoryWiseProduct(Request $request)
    {
        $user = $request->user();
        $limit = $request->limit ? $request->limit : 15;
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;
        $mostProductCategory = [];

        switch ($user) {
            case $user->hasPermissionTo(Permission::SUPER_ADMIN):

                $mostProductCategory = DB::table('category_product')
                    ->select(
                        'categories.id as category_id',
                        'categories.name as category_name',
                        'shops.name as shop_name',
                        DB::raw('COUNT(category_product.product_id) as product_count')
                    )
                    ->where('categories.language', '=', $language)
                    ->join('products', 'category_product.product_id', '=', 'products.id')
                    ->join('categories', 'category_product.category_id', '=', 'categories.id')
                    ->join('shops', 'products.shop_id', '=', 'shops.id')
                    ->groupBy('categories.id', 'categories.name', 'shops.name')
                    ->orderBy('product_count', 'DESC')
                    ->limit($limit)
                    ->get();

                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):

                $shops = $user->shops()->pluck('id') ?? [];
                $mostProductCategory = DB::table('category_product')
                    ->select(
                        'categories.id as category_id',
                        'categories.name as category_name',
                        'shops.name as shop_name',
                        DB::raw('COUNT(category_product.product_id) as product_count')
                    )
                    ->where('categories.language', '=', $language)
                    ->whereIn('shops.id', $shops)
                    ->join('products', 'category_product.product_id', '=', 'products.id')
                    ->join('categories', 'category_product.category_id', '=', 'categories.id')
                    ->join('shops', 'products.shop_id', '=', 'shops.id')
                    ->groupBy('categories.id', 'categories.name', 'shops.name')
                    ->orderBy('product_count', 'DESC')
                    ->limit($limit)
                    ->get();

                break;

            case $user->hasPermissionTo(Permission::STAFF):

                $shop = $user->shop_id ?? null;
                if (isset($shop)) {
                    $mostProductCategory = DB::table('category_product')
                        ->select(
                            'categories.id as category_id',
                            'categories.name as category_name',
                            'shops.name as shop_name',
                            DB::raw('COUNT(category_product.product_id) as product_count')
                        )
                        ->where('categories.language', '=', $language)
                        ->where('shops.id', '=', $shop)
                        ->join('products', 'category_product.product_id', '=', 'products.id')
                        ->join('categories', 'category_product.category_id', '=', 'categories.id')
                        ->join('shops', 'products.shop_id', '=', 'shops.id')
                        ->groupBy('categories.id', 'categories.name', 'shops.name')
                        ->orderBy('product_count', 'DESC')
                        ->limit($limit)
                        ->get();
                } else {
                    $mostProductCategory = [];
                }

                break;
        }

        return $mostProductCategory;
    }
    /**
     * categoryWiseProductSale
     *
     * @param  Request $request
     * @return void
     */
    public function categoryWiseProductSale(Request $request)
    {
        $user = $request->user();
        $limit = $request->limit ? $request->limit : 15;
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;
        $mostSoldProductCategory = [];

        switch ($user) {
            case $user->hasPermissionTo(Permission::SUPER_ADMIN):

                $mostSoldProductCategory = DB::table('categories')
                    ->select(
                        'categories.id as category_id',
                        'categories.name as category_name',
                        'shops.name as shop_name',
                        DB::raw('sum(order_product.order_quantity) as total_sales')
                    )
                    ->leftJoin('category_product', 'category_product.category_id', '=', 'categories.id')
                    ->leftJoin('products', 'category_product.product_id', '=', 'products.id')
                    ->leftJoin('shops', 'products.shop_id', '=', 'shops.id')
                    ->leftJoin('order_product', 'order_product.product_id', '=', 'products.id')
                    ->leftJoin('orders', 'order_product.order_id', '=', 'orders.id')
                    ->where('orders.parent_id', null)
                    ->where('orders.order_status', 'order-completed')
                    ->where('categories.language', '=', $language)
                    ->groupBy('categories.id', 'categories.name', 'shops.name')
                    ->orderBy('total_sales', 'desc')
                    ->limit($limit)
                    ->get();

                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):

                $shops = $user->shops()->pluck('id') ?? [];
                $mostSoldProductCategory = DB::table('categories')
                    ->select(
                        'categories.id as category_id',
                        'categories.name as category_name',
                        'shops.name as shop_name',
                        DB::raw('sum(order_product.order_quantity) as total_sales')
                    )
                    ->leftJoin('category_product', 'category_product.category_id', '=', 'categories.id')
                    ->leftJoin('products', 'category_product.product_id', '=', 'products.id')
                    ->leftJoin('shops', 'products.shop_id', '=', 'shops.id')
                    ->leftJoin('order_product', 'order_product.product_id', '=', 'products.id')
                    ->leftJoin('orders', 'order_product.order_id', '=', 'orders.id')
                    ->whereIn('shops.id', $shops)
                    ->where('orders.parent_id', null)
                    ->where('orders.order_status', 'order-completed')
                    ->where('categories.language', '=', $language)
                    ->groupBy('categories.id', 'categories.name', 'shops.name')
                    ->orderBy('total_sales', 'desc')
                    ->limit($limit)
                    ->get();

                break;

            case $user->hasPermissionTo(Permission::STAFF):

                $shop = $user->shop_id ?? null;
                if (isset($shop)) {
                    $mostSoldProductCategory = DB::table('categories')
                        ->select(
                            'categories.id as category_id',
                            'categories.name as category_name',
                            'shops.name as shop_name',
                            DB::raw('sum(order_product.order_quantity) as total_sales')
                        )
                        ->leftJoin('category_product', 'category_product.category_id', '=', 'categories.id')
                        ->leftJoin('products', 'category_product.product_id', '=', 'products.id')
                        ->leftJoin('shops', 'products.shop_id', '=', 'shops.id')
                        ->leftJoin('order_product', 'order_product.product_id', '=', 'products.id')
                        ->leftJoin('orders', 'order_product.order_id', '=', 'orders.id')
                        ->where('shops.id', '=', $shop)
                        ->where('orders.parent_id', null)
                        ->where('orders.order_status', 'order-completed')
                        ->where('categories.language', '=', $language)
                        ->groupBy('categories.id', 'categories.name', 'shops.name')
                        ->orderBy('total_sales', 'desc')
                        ->limit($limit)
                        ->get();
                } else {
                    $mostSoldProductCategory = [];
                }

                break;
        }

        return $mostSoldProductCategory;
    }


    /**
     * topRatedProducts
     *
     * @param  Request $request
     * @return void
     */
    public function topRatedProducts(Request $request)
    {
        $user = $request->user();
        $limit = $request->limit ? $request->limit : 10;
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;
        $topRatedProducts = [];

        switch ($user) {
            case $user->hasPermissionTo(Permission::SUPER_ADMIN):
                $topRatedProducts = DB::table('reviews')
                    ->join('products', 'products.id', '=', 'reviews.product_id')
                    ->join('types', 'types.id', '=', 'products.type_id')
                    ->select(
                        'products.id as id',
                        'products.name as name',
                        'products.slug as slug',
                        'products.price as regular_price',
                        'products.sale_price as sale_price',
                        'products.min_price as min_price',
                        'products.max_price as max_price',
                        'products.product_type as product_type',
                        'products.description as description',
                        'types.id as type_id',
                        'types.slug as type_slug',
                        DB::raw('JSON_UNQUOTE(products.image) AS image_json'),
                        DB::raw('SUM(reviews.rating) as total_rating'),
                        DB::raw('COUNT(reviews.id) as rating_count'),
                        DB::raw('SUM(reviews.rating) / COUNT(reviews.id) as actual_rating'),
                    )
                    ->where('products.language', '=', $language)
                    ->groupBy(
                        'products.id',
                        'products.name',
                        'products.slug',
                        'products.price',
                        'products.sale_price',
                        'products.min_price',
                        'products.max_price',
                        'products.product_type',
                        'products.description',
                        'products.image',
                        'types.id',
                        'types.slug'
                    )
                    ->orderBy('actual_rating', 'desc')
                    ->limit($limit)
                    ->get();
                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):

                $shops = $user->shops()->pluck('id') ?? [];
                $topRatedProducts = DB::table('reviews')
                    ->join('products', 'products.id', '=', 'reviews.product_id')
                    ->join('types', 'types.id', '=', 'products.type_id')
                    ->select(
                        'products.id as id',
                        'products.name as name',
                        'products.slug as slug',
                        'products.price as regular_price',
                        'products.sale_price as sale_price',
                        'products.min_price as min_price',
                        'products.max_price as max_price',
                        'products.product_type as product_type',
                        'products.description as description',
                        'types.id as type_id',
                        'types.slug as type_slug',
                        DB::raw('JSON_UNQUOTE(products.image) AS image_json'),
                        DB::raw('SUM(reviews.rating) as total_rating'),
                        DB::raw('COUNT(reviews.id) as rating_count'),
                        DB::raw('SUM(reviews.rating) / COUNT(reviews.id) as actual_rating'),
                    )
                    ->where('products.language', '=', $language)
                    ->whereIn('products.shop_id', $shops)
                    ->groupBy(
                        'products.id',
                        'products.name',
                        'products.slug',
                        'products.price',
                        'products.sale_price',
                        'products.min_price',
                        'products.max_price',
                        'products.product_type',
                        'products.description',
                        'products.image',
                        'types.id',
                        'types.slug'
                    )
                    ->orderBy('actual_rating', 'desc')
                    ->limit($limit)
                    ->get();

                break;

            case $user->hasPermissionTo(Permission::STAFF):

                $shop = $user->shop_id ?? [];
                if (isset($shop)) {
                    $topRatedProducts = DB::table('reviews')
                        ->join('products', 'products.id', '=', 'reviews.product_id')
                        ->join('types', 'types.id', '=', 'products.type_id')
                        ->select(
                            'products.id as id',
                            'products.name as name',
                            'products.slug as slug',
                            'products.price as regular_price',
                            'products.sale_price as sale_price',
                            'products.min_price as min_price',
                            'products.max_price as max_price',
                            'products.product_type as product_type',
                            'products.description as description',
                            'types.id as type_id',
                            'types.slug as type_slug',
                            DB::raw('JSON_UNQUOTE(products.image) AS image_json'),
                            DB::raw('SUM(reviews.rating) as total_rating'),
                            DB::raw('COUNT(reviews.id) as rating_count'),
                            DB::raw('SUM(reviews.rating) / COUNT(reviews.id) as actual_rating'),
                        )
                        ->where('products.language', '=', $language)
                        ->where('products.shop_id', '=', $shop)
                        ->groupBy(
                            'products.id',
                            'products.name',
                            'products.slug',
                            'products.price',
                            'products.sale_price',
                            'products.min_price',
                            'products.max_price',
                            'products.product_type',
                            'products.description',
                            'products.image',
                            'types.id',
                            'types.slug'
                        )
                        ->orderBy('actual_rating', 'desc')
                        ->limit($limit)
                        ->get();
                } else {
                    $topRatedProducts = [];
                }


                break;
        }

        foreach ($topRatedProducts as $row) {
            $row->image = json_decode($row->image_json, true);
            unset($row->image_json);
        }

        return $topRatedProducts;
    }

    // ──────────────────────────────────────────────────────────────
    // Marketplace analytics (D1) — SUPER_ADMIN dashboard widgets. Registered under the
    // admin route group, so these are platform-wide (no per-shop scoping). All bounded by
    // an optional from/to date range + a LIMIT so they stay cheap at 500k+ orders.
    // ──────────────────────────────────────────────────────────────

    private function applyDateRange($query, Request $request, string $col): void
    {
        if ($request->filled('from')) {
            $query->where($col, '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where($col, '<=', $request->to);
        }
    }

    /** Sales grouped by the customer's city (from the parent order's shipping_address JSON). */
    public function cityWiseSales(Request $request)
    {
        $limit = (int) ($request->limit ?? 15);
        $q = DB::table('orders as child')
            ->join('orders as parent', 'child.parent_id', '=', 'parent.id')
            ->where('child.order_status', OrderStatus::COMPLETED)
            ->where('parent.order_status', OrderStatus::COMPLETED);
        $this->applyDateRange($q, $request, 'child.created_at');

        return $q->select(
            DB::raw("COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(parent.shipping_address, '$.city'))), ''), NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(parent.shipping_address, '$.address.city'))), ''), 'Unknown') as city"),
            DB::raw('SUM(child.paid_total) as revenue'),
            DB::raw('COUNT(child.id) as orders')
        )->groupBy('city')->orderByDesc('revenue')->limit($limit)->get();
    }

    /** Vendors ranked by completed-order revenue. */
    public function topVendors(Request $request)
    {
        $limit = (int) ($request->limit ?? 10);
        $q = DB::table('orders as child')
            ->join('shops', 'shops.id', '=', 'child.shop_id')
            ->whereNotNull('child.parent_id')
            ->where('child.order_status', OrderStatus::COMPLETED);
        $this->applyDateRange($q, $request, 'child.created_at');

        return $q->select(
            'shops.id as shop_id',
            'shops.name as shop_name',
            DB::raw('SUM(child.paid_total) as revenue'),
            DB::raw('COUNT(child.id) as orders')
        )->groupBy('shops.id', 'shops.name')->orderByDesc('revenue')->limit($limit)->get();
    }

    /** Per-vendor revenue / cost / profit from the ledger (empty when the ledger is off). */
    public function vendorProfitability(Request $request)
    {
        $limit = (int) ($request->limit ?? 10);
        $q = DB::table('vendor_ledger_entries as v')->leftJoin('shops', 'shops.id', '=', 'v.shop_id');
        $this->applyDateRange($q, $request, 'v.earned_at');

        return $q->select(
            'v.shop_id',
            DB::raw('MAX(shops.name) as shop_name'),
            DB::raw('SUM(v.product_value) as revenue'),
            DB::raw('SUM(v.cost_value) as cost'),
            DB::raw('SUM(v.vendor_profit) as profit'),
            DB::raw('SUM(v.amount) as net_earning')
        )->groupBy('v.shop_id')->orderByDesc('profit')->limit($limit)->get();
    }

    /** Suborders awaiting vendor assignment (count + a recent list). */
    public function pendingFulfillments(Request $request)
    {
        $limit = (int) ($request->limit ?? 20);
        $base = DB::table('orders')
            ->whereNotNull('parent_id')
            ->whereIn('order_status', [OrderStatus::PENDING, OrderStatus::PROCESSING])
            ->where(fn ($w) => $w->whereNull('vendor_shop_id')->orWhere('assignment_status', 'unassigned'));

        return [
            'count'  => (clone $base)->count(),
            'orders' => (clone $base)->select('id', 'tracking_number', 'shop_id', 'order_status', 'assignment_status', 'created_at')
                ->orderByDesc('id')->limit($limit)->get(),
        ];
    }

    /** Courier-fulfilled orders (count + a recent list). */
    public function courierOrders(Request $request)
    {
        $limit = (int) ($request->limit ?? 20);
        $base = DB::table('orders')->whereNotNull('parent_id')->whereIn('delivery_mode', ['courier_admin', 'courier_dp']);
        $this->applyDateRange($base, $request, 'created_at');

        return [
            'count'  => (clone $base)->count(),
            'orders' => (clone $base)->select('id', 'tracking_number', 'shop_id', 'order_status', 'delivery_mode', 'created_at')
                ->orderByDesc('id')->limit($limit)->get(),
        ];
    }

    /** Products ranked by units sold (complements the top-RATED widget). */
    public function topSellingProducts(Request $request)
    {
        $limit = (int) ($request->limit ?? 10);
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $q = DB::table('order_product')
            ->join('orders', 'order_product.order_id', '=', 'orders.id')
            ->join('products', 'order_product.product_id', '=', 'products.id')
            ->whereNull('orders.parent_id')
            ->where('orders.order_status', OrderStatus::COMPLETED)
            ->where('products.language', $language);
        $this->applyDateRange($q, $request, 'orders.created_at');

        $rows = $q->select(
            'products.id as id',
            'products.name as name',
            'products.slug as slug',
            'products.price as regular_price',
            DB::raw('JSON_UNQUOTE(products.image) AS image_json'),
            DB::raw('SUM(order_product.order_quantity) as total_sold')
        )->groupBy('products.id', 'products.name', 'products.slug', 'products.price', 'products.image')
            ->orderByDesc('total_sold')->limit($limit)->get();

        foreach ($rows as $row) {
            $row->image = json_decode($row->image_json, true);
            unset($row->image_json);
        }
        return $rows;
    }
}
