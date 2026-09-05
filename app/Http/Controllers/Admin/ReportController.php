<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    // ── Dashboard ──────────────────────────────────────────────────────────────

    public function dashboard(): View
    {
        abort_if(! auth()->user()->can('view_reports'), 403);

        $threshold = (int) setting('inventory.low_stock_threshold', 5);

        $stats = [
            // Revenue
            'total_revenue' => Order::where('payment_status', 'paid')->sum('total'),
            'today_revenue' => Order::where('payment_status', 'paid')->whereDate('paid_at', today())->sum('total'),
            'month_revenue' => Order::where('payment_status', 'paid')->whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->sum('total'),
            // Orders
            'total_orders' => Order::count(),
            'paid_orders' => Order::where('payment_status', 'paid')->count(),
            'pending_orders' => Order::where('payment_status', 'unpaid')->whereNotIn('status', ['cancelled', 'failed'])->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count(),
            'refunded_orders' => Order::where('payment_status', 'refunded')->count(),
            // Users
            'total_users' => User::count(),
            'new_users_month' => User::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            // Products
            'total_products' => Product::count(),
            'active_products' => Product::where('is_active', true)->count(),
            'low_stock_products' => Product::where('is_active', true)->where('stock_quantity', '<=', $threshold)->where('stock_quantity', '>', 0)->count(),
            'out_of_stock' => Product::where('is_active', true)->where('stock_status', 'out_of_stock')->count(),
        ];

        $recentOrders = Order::with('user')
            ->where('payment_status', 'paid')
            ->latest('paid_at')
            ->limit(8)
            ->get();

        $topProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.payment_status', 'paid')
            ->whereNull('products.deleted_at')
            ->select('products.id', 'products.title_fa', DB::raw('SUM(order_items.quantity) as total_sold'))
            ->groupBy('products.id', 'products.title_fa')
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get();

        $lowStockProducts = Product::where('is_active', true)
            ->where('stock_quantity', '<=', $threshold)
            ->orderBy('stock_quantity')
            ->limit(6)
            ->get(['id', 'title_fa', 'stock_quantity', 'stock_status']);

        $recentLogs = ActivityLog::with('admin')
            ->latest()
            ->limit(5)
            ->get();

        return view('admin.reports.dashboard', compact('stats', 'recentOrders', 'topProducts', 'lowStockProducts', 'recentLogs'));
    }

    // ── Sales ──────────────────────────────────────────────────────────────────

    public function sales(Request $request): View
    {
        abort_if(! auth()->user()->can('view_sales_reports'), 403);

        $dateFrom = $request->get('date_from', now()->startOfMonth()->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        $query = Order::where('payment_status', 'paid')
            ->when($dateFrom, fn ($q) => $q->whereDate('paid_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('paid_at', '<=', $dateTo));

        if ($gateway = $request->get('gateway')) {
            $query->whereHas('paymentTransactions', fn ($q) => $q->where('gateway', $gateway));
        }

        $summary = [
            'total_revenue' => (clone $query)->sum('total'),
            'total_orders' => (clone $query)->count(),
            'refunded_amount' => Order::where('payment_status', 'refunded')->whereDate('updated_at', '>=', $dateFrom)->whereDate('updated_at', '<=', $dateTo)->sum('total'),
        ];
        $summary['avg_order_value'] = $summary['total_orders'] > 0 ? intval($summary['total_revenue'] / $summary['total_orders']) : 0;

        $byDelivery = Order::where('payment_status', 'paid')
            ->when($dateFrom, fn ($q) => $q->whereDate('paid_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('paid_at', '<=', $dateTo))
            ->select('status', DB::raw('count(*) as count'), DB::raw('SUM(total) as revenue'))
            ->groupBy('status')
            ->get();

        $byGateway = DB::table('payment_transactions')
            ->where('status', 'success')
            ->when($dateFrom, fn ($q) => $q->whereDate('paid_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('paid_at', '<=', $dateTo))
            ->select('gateway', DB::raw('count(*) as count'), DB::raw('SUM(amount) as revenue'))
            ->groupBy('gateway')
            ->get();

        $dailySales = Order::where('payment_status', 'paid')
            ->when($dateFrom, fn ($q) => $q->whereDate('paid_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('paid_at', '<=', $dateTo))
            ->select(DB::raw('DATE(paid_at) as date'), DB::raw('count(*) as order_count'), DB::raw('SUM(total) as revenue'))
            ->groupBy(DB::raw('DATE(paid_at)'))
            ->orderBy(DB::raw('DATE(paid_at)'))
            ->get();

        $todayRevenue = Order::where('payment_status', 'paid')->whereDate('paid_at', today())->sum('total');

        return view('admin.reports.sales', compact('summary', 'byDelivery', 'byGateway', 'dailySales', 'dateFrom', 'dateTo', 'todayRevenue'));
    }

    public function salesExport(Request $request): Response
    {
        abort_if(! auth()->user()->can('export_reports'), 403);

        $dateFrom = $request->get('date_from', now()->startOfMonth()->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        $rows = Order::where('payment_status', 'paid')
            ->when($dateFrom, fn ($q) => $q->whereDate('paid_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('paid_at', '<=', $dateTo))
            ->select(DB::raw('DATE(paid_at) as date'), DB::raw('count(*) as order_count'), DB::raw('SUM(total) as revenue'))
            ->groupBy(DB::raw('DATE(paid_at)'))
            ->orderBy(DB::raw('DATE(paid_at)'))
            ->get();

        $csv = "\xEF\xBB\xBF";
        $csv .= "تاریخ,تعداد سفارش,درآمد (تومان)\n";
        foreach ($rows as $r) {
            $csv .= "{$r->date},{$r->order_count},{$r->revenue}\n";
        }

        ActivityLogService::log('report.export', null, "خروجی گزارش فروش: {$dateFrom} تا {$dateTo}");

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sales-report-'.now()->format('Ymd').'.csv"',
        ]);
    }

    // ── Orders ─────────────────────────────────────────────────────────────────

    public function orders(Request $request): View
    {
        abort_if(! auth()->user()->can('view_order_reports'), 403);

        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        $status = $request->get('status');
        $payStatus = $request->get('payment_status');

        $q = Order::query()
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($payStatus, fn ($q) => $q->where('payment_status', $payStatus));

        $byStatus = Order::select('status', DB::raw('count(*) as count'))->groupBy('status')->orderByDesc('count')->get();
        $byPayment = Order::select('payment_status', DB::raw('count(*) as count'))->groupBy('payment_status')->orderByDesc('count')->get();

        $orders = $q->with('user')->latest()->paginate(20)->withQueryString();

        $summary = [
            'total' => Order::count(),
            'paid' => Order::where('payment_status', 'paid')->count(),
            'cancelled' => Order::where('status', 'cancelled')->count(),
            'refunded' => Order::where('payment_status', 'refunded')->count(),
            'processing' => Order::where('status', 'processing')->count(),
            'delivered' => Order::where('status', 'delivered')->count(),
        ];

        return view('admin.reports.orders', compact('orders', 'summary', 'byStatus', 'byPayment', 'dateFrom', 'dateTo', 'status', 'payStatus'));
    }

    public function ordersExport(Request $request): Response
    {
        abort_if(! auth()->user()->can('export_reports'), 403);

        $rows = Order::with('user')
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->cursor();

        $statusLabels = [
            'pending_payment' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده',
            'processing' => 'در حال پردازش', 'shipped' => 'ارسال شده',
            'delivered' => 'تحویل داده شده', 'cancelled' => 'لغو شده',
            'refunded' => 'مسترد شده', 'failed' => 'ناموفق',
        ];

        $csv = "\xEF\xBB\xBF";
        $csv .= "شماره سفارش,موبایل مشتری,مبلغ کل,وضعیت سفارش,وضعیت پرداخت,نوع تحویل,تاریخ ثبت,تاریخ پرداخت\n";
        foreach ($rows as $o) {
            $csv .= implode(',', [
                $o->order_number,
                $o->user?->mobile ?? '',
                $o->total,
                $statusLabels[$o->status] ?? $o->status,
                $o->payment_status,
                $o->created_at->format('Y-m-d H:i'),
                $o->paid_at?->format('Y-m-d H:i') ?? '',
            ])."\n";
        }

        ActivityLogService::log('report.export', null, 'خروجی گزارش سفارشات');

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="orders-report-'.now()->format('Ymd').'.csv"',
        ]);
    }

    // ── Products ───────────────────────────────────────────────────────────────

    public function products(Request $request): View
    {
        abort_if(! auth()->user()->can('view_product_reports'), 403);

        $categoryId = $request->get('category_id');
        $stockStatus = $request->get('stock_status');
        $threshold = (int) setting('inventory.low_stock_threshold', 5);

        $summary = [
            'total' => Product::count(),
            'active' => Product::where('is_active', true)->count(),
            'inactive' => Product::where('is_active', false)->count(),
            'featured' => Product::where('is_featured', true)->count(),
            'flash_sale' => Product::where('is_flash_sale', true)->count(),
            'best_seller' => Product::where('is_best_seller', true)->count(),

            'out_of_stock' => Product::where('stock_status', 'out_of_stock')->count(),
            'low_stock' => Product::where('stock_quantity', '<=', $threshold)->where('stock_quantity', '>', 0)->count(),
        ];

        $topSelling = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.payment_status', 'paid')
            ->whereNull('products.deleted_at')
            ->select('products.id', 'products.title_fa', DB::raw('SUM(order_items.quantity) as total_sold'), DB::raw('SUM(order_items.total_price) as total_revenue'))
            ->groupBy('products.id', 'products.title_fa')
            ->orderByDesc('total_sold')
            ->limit(15)
            ->get();

        $products = Product::with('category')
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($stockStatus, fn ($q) => $q->where('stock_status', $stockStatus))
            ->latest()
            ->paginate(20)->withQueryString();

        $categories = Category::active()->orderBy('name_fa')->get(['id', 'name_fa']);

        return view('admin.reports.products', compact('summary', 'topSelling', 'products', 'categories', 'categoryId', 'stockStatus'));
    }

    public function productsExport(Request $request): Response
    {
        abort_if(! auth()->user()->can('export_reports'), 403);

        $rows = Product::with('category')
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->orderBy('title_fa')
            ->cursor();

        $csv = "\xEF\xBB\xBF";
        $csv .= "عنوان محصول,SKU,دسته‌بندی,نوع محصول,موجودی,قیمت,وضعیت\n";
        foreach ($rows as $p) {
            $csv .= implode(',', [
                $p->title_fa,
                $p->sku ?? '',
                $p->category?->name_fa ?? '',
                $p->stock_quantity,
                $p->price,
                $p->is_active ? 'فعال' : 'غیرفعال',
            ])."\n";
        }

        ActivityLogService::log('report.export', null, 'خروجی گزارش محصولات');

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products-report-'.now()->format('Ymd').'.csv"',
        ]);
    }

    // ── Categories ─────────────────────────────────────────────────────────────

    public function categories(): View
    {
        abort_if(! auth()->user()->can('view_category_reports'), 403);

        $categories = Category::withCount(['products', 'products as active_products_count' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('products_count')
            ->get();

        $summary = [
            'total' => Category::count(),
            'active' => Category::where('is_active', true)->count(),
            'inactive' => Category::where('is_active', false)->count(),
            'root' => Category::whereNull('parent_id')->count(),
        ];

        // Sales by category (via order_items -> products)
        $salesByCategory = DB::table('categories')
            ->leftJoin('products', 'categories.id', '=', 'products.category_id')
            ->leftJoin('order_items', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('orders', function ($join) {
                $join->on('order_items.order_id', '=', 'orders.id')
                    ->where('orders.payment_status', '=', 'paid');
            })
            ->select('categories.id', 'categories.name_fa', DB::raw('COALESCE(SUM(order_items.total_price), 0) as revenue'), DB::raw('COALESCE(SUM(order_items.quantity), 0) as items_sold'))
            ->groupBy('categories.id', 'categories.name_fa')
            ->orderByDesc('revenue')
            ->get();

        return view('admin.reports.categories', compact('categories', 'summary', 'salesByCategory'));
    }

    // ── Users ──────────────────────────────────────────────────────────────────

    public function users(Request $request): View
    {
        abort_if(! auth()->user()->can('view_user_reports'), 403);

        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        $summary = [
            'total' => User::count(),
            'active' => User::where('status', 'active')->count(),
            'blocked' => User::where('status', 'blocked')->count(),
            'with_orders' => User::whereHas('orders')->count(),
            'new_month' => User::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
        ];

        $newByDate = User::when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy(DB::raw('DATE(created_at)'))
            ->get();

        $topCustomers = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->where('orders.payment_status', 'paid')
            ->select('users.id', 'users.full_name', 'users.mobile',
                DB::raw('count(*) as order_count'),
                DB::raw('SUM(orders.total) as total_spent')
            )
            ->groupBy('users.id', 'users.full_name', 'users.mobile')
            ->orderByDesc('total_spent')
            ->limit(10)
            ->get();

        $recentUsers = User::latest()->limit(10)->get(['id', 'full_name', 'mobile', 'status', 'created_at']);

        return view('admin.reports.users', compact('summary', 'newByDate', 'topCustomers', 'recentUsers', 'dateFrom', 'dateTo'));
    }

    public function usersExport(Request $request): Response
    {
        abort_if(! auth()->user()->can('export_reports'), 403);

        $rows = DB::table('users')
            ->leftJoin(DB::raw('(SELECT user_id, count(*) as orders_count, SUM(total) as total_spent FROM orders WHERE payment_status="paid" GROUP BY user_id) as os'), 'users.id', '=', 'os.user_id')
            ->select('users.full_name', 'users.mobile', 'users.email', 'users.status', 'users.created_at',
                DB::raw('COALESCE(os.orders_count, 0) as orders_count'),
                DB::raw('COALESCE(os.total_spent, 0) as total_spent')
            )
            ->when($request->date_from, fn ($q) => $q->whereDate('users.created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('users.created_at', '<=', $request->date_to))
            ->orderByDesc('users.created_at')
            ->cursor();

        $csv = "\xEF\xBB\xBF";
        $csv .= "نام,موبایل,ایمیل,وضعیت,تعداد سفارش,جمع خرید,تاریخ ثبت‌نام\n";
        foreach ($rows as $r) {
            $csv .= implode(',', [$r->full_name ?? '', $r->mobile, $r->email ?? '', $r->status, $r->orders_count, $r->total_spent, $r->created_at])."\n";
        }

        ActivityLogService::log('report.export', null, 'خروجی گزارش کاربران');

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="users-report-'.now()->format('Ymd').'.csv"',
        ]);
    }

    // ── Low Stock ──────────────────────────────────────────────────────────────

    public function lowStock(): View
    {
        abort_if(! auth()->user()->can('view_low_stock_reports'), 403);

        $threshold = (int) setting('inventory.low_stock_threshold', 5);

        $physicalLowStock = Product::where('is_active', true)
            ->where('stock_quantity', '<=', $threshold)
            ->where('stock_quantity', '>', 0)
            ->with('category')
            ->orderBy('stock_quantity')
            ->get();

        $outOfStock = Product::where('is_active', true)
            ->where('stock_status', 'out_of_stock')
            ->with('category')
            ->orderBy('title_fa')
            ->get();

        // Only ->count() is used for these two in the view — query it directly instead of materializing rows.
        $comingSoon = Product::where('is_active', true)
            ->where('stock_status', 'coming_soon')
            ->count();

        return view('admin.reports.low-stock', compact('physicalLowStock', 'outOfStock', 'comingSoon', 'threshold'));
    }

    public function lowStockExport(): Response
    {
        abort_if(! auth()->user()->can('export_reports'), 403);

        $threshold = (int) setting('inventory.low_stock_threshold', 5);

        $rows = Product::where('is_active', true)
            ->where(function ($q) use ($threshold) {
                $q->where(function ($inner) use ($threshold) {
                    $inner->where('stock_quantity', '<=', $threshold);
                })->orWhere('stock_status', 'out_of_stock');
            })
            ->with('category')
            ->orderBy('stock_quantity')
            ->get();

        $csv = "\xEF\xBB\xBF";
        $csv .= "عنوان محصول,SKU,دسته‌بندی,نوع,موجودی,وضعیت موجودی\n";
        foreach ($rows as $p) {
            $csv .= implode(',', [$p->title_fa, $p->sku ?? '', $p->category?->name_fa ?? '', $p->stock_quantity, $p->stock_status])."\n";
        }

        ActivityLogService::log('report.export', null, 'خروجی گزارش کمبود موجودی');

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="low-stock-report-'.now()->format('Ymd').'.csv"',
        ]);
    }
}
