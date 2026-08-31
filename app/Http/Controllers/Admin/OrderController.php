<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ActivityLogService;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService)
    {
    }

    public function index(Request $request)
    {
        abort_if(!auth()->user()->can('view_orders'), 403);

        $query = Order::with(['user', 'latestTransaction'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('full_name', 'like', "%{$search}%")
                      ->orWhere('mobile', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $orders = $query->paginate(20)->withQueryString();

        $statusCounts = Order::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.orders.index', compact('orders', 'statusCounts'));
    }

    public function show(Order $order)
    {
        abort_if(!auth()->user()->can('view_orders'), 403);

        $order->load(['user', 'items.product', 'items.digitalCode', 'paymentTransactions', 'shippingAddress', 'coupon']);

        return view('admin.orders.show', compact('order'));
    }

    public function updateStatus(Request $request, Order $order)
    {
        abort_if(!auth()->user()->can('update_order_status'), 403);

        $request->validate([
            'status' => 'required|in:pending_payment,paid,processing,shipped,delivered,cancelled,refunded,failed',
        ]);

        $newStatus = $request->status;
        $beforeStatus = $order->status;

        // BUG-005: a terminal order (cancelled/refunded/delivered) must not
        // be pushed into any other status from this generic dropdown --
        // without this guard the state machine has no floor and e.g. a
        // refunded order could be silently reopened to "processing".
        if (in_array($order->status, ['cancelled', 'refunded', 'delivered'], true) && $newStatus !== $order->status) {
            return back()->with('error', 'سفارش در وضعیت نهایی است و قابل تغییر نیست.');
        }

        if ($newStatus === 'paid' && !$order->paid_at) {
            // Delegate to the canonical successful-payment lifecycle so admin
            // mark-paid performs the same stock/coupon/cart/digital-code
            // effects as a real payment, instead of a divergent subset.
            $this->orderService->markAsPaid($order);
        } elseif (in_array($newStatus, ['cancelled', 'refunded'], true)) {
            // Delegate to the canonical cancel lifecycle so admin
            // cancel/refund releases digital codes and restores physical
            // stock, instead of only flipping the status column.
            try {
                $this->orderService->cancelOrder($order, $newStatus);
            } catch (\Exception $e) {
                return back()->with('error', $e->getMessage());
            }
        } elseif (in_array($newStatus, ['processing', 'shipped', 'delivered'], true)) {
            // Delegate to the canonical fulfillment-status lifecycle so these
            // transitions are locked/validated the same way paid/cancelled
            // already are, instead of a raw, unguarded status column write.
            try {
                match ($newStatus) {
                    'processing' => $this->orderService->markProcessing($order),
                    'shipped'    => $this->orderService->markShipped($order),
                    'delivered'  => $this->orderService->markDelivered($order),
                };
            } catch (\Exception $e) {
                return back()->with('error', $e->getMessage());
            }
        } else {
            return back()->with('error', 'این تغییر وضعیت پشتیبانی نمی‌شود.');
        }

        $order->refresh();
        ActivityLogService::log('order.status_update', $order, "تغییر وضعیت سفارش #{$order->order_number}", [
            'before' => $beforeStatus, 'after' => $order->status,
        ]);

        return back()->with('success', 'وضعیت سفارش به‌روزرسانی شد.');
    }

    public function saveNote(Request $request, Order $order)
    {
        abort_if(!auth()->user()->can('create_order_notes'), 403);

        $request->validate(['admin_note' => 'nullable|string|max:2000']);

        $order->update(['admin_note' => $request->admin_note]);

        ActivityLogService::log('order.note_update', $order, "به‌روزرسانی یادداشت سفارش #{$order->order_number}");

        return back()->with('success', 'یادداشت ذخیره شد.');
    }
}
