<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService)
    {
    }

    public function index(Request $request): View
    {
        $user = auth()->user();
        $tab = $request->get('tab', 'active');

        $orders = match ($tab) {
            'delivered' => $user->orders()->with('shippingAddress')->where('status', 'delivered')->latest()->paginate(10),
            'cancelled' => $user->orders()->with('shippingAddress')->whereIn('status', ['cancelled', 'refunded', 'failed'])->latest()->paginate(10),
            default     => $user->orders()->with('shippingAddress')->whereIn('status', ['pending_payment', 'paid', 'processing', 'shipped'])->latest()->paginate(10),
        };

        return view('profile.orders', compact('orders', 'tab'));
    }

    public function show(string $orderNumber): View
    {
        // Query already scopes to the current owner, so this never rejects
        // here -- it exists to make OrderPolicy::view() a genuinely
        // invoked authorization path (BUG-089), not decorative dead code,
        // without weakening the existing 404-for-non-owner behavior below.
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', auth()->id())
            ->with(['items.product', 'shippingAddress', 'shippingMethod', 'latestTransaction'])
            ->firstOrFail();

        $this->authorize('view', $order);

        return view('profile.order-detail', compact('order'));
    }

    public function cancel(Order $order): JsonResponse
    {
        // Route-model-bound $order is not pre-scoped to the owner, so
        // ownership must be enforced here explicitly (BUG-089: route-model
        // binding must not bypass authorization).
        if ($order->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
        }

        if (auth()->user()->cannot('cancel', $order)) {
            // Ownership already confirmed above, so a policy rejection here
            // can only be the order's status -- keep OrderService's own
            // specific Persian message rather than a generic "unauthorized".
            return response()->json(['success' => false, 'message' => 'این سفارش قابل لغو نیست.'], 422);
        }

        try {
            $this->orderService->cancelOrder($order);
            return response()->json(['success' => true, 'message' => 'سفارش لغو شد.']);
        } catch (QueryException $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.'], 500);
        } catch (\Exception $e) {
            report($e);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
