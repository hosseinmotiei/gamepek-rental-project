<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        abort_if(! auth()->user()->can('view_dashboard'), 403);

        $today = Carbon::today();

        $stats = [
            'revenue_today' => Order::whereDate('created_at', $today)->whereIn('status', ['paid', 'processing', 'shipped', 'delivered'])->sum('total'),
            'orders_today' => Order::whereDate('created_at', $today)->count(),
            'pending_payment' => Order::where('status', 'pending_payment')->count(),
            'active_products' => Product::where('is_active', true)->count(),
            'total_users' => User::count(),
        ];

        return view('admin.dashboard', compact('stats'));
    }
}
