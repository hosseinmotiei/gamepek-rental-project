<?php

namespace App\Http\Controllers;

use App\Http\Requests\Checkout\PlaceOrderRequest;
use App\Models\Order;
use App\Models\RentalApplication;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\UserActivityLogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function __construct(
        private CartService $cartService,
        private OrderService $orderService,
        private PaymentService $paymentService
    ) {}

    public function shipping(): View|RedirectResponse
    {
        $summary = $this->cartService->getCartSummary();

        if ($summary['items']->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'سبد خرید شما خالی است.');
        }

        $addresses = auth()->user()->addresses()->orderByDesc('is_default')->get();

        $defaultCity = $addresses->first()?->city;
        $shippingMethods = ShippingMethod::active()
            ->when($defaultCity, fn ($q) => $q->forCity($defaultCity))
            ->orderBy('sort_order')
            ->get();

        return view('checkout.shipping', compact('summary', 'addresses', 'shippingMethods'));
    }

    public function placeOrder(PlaceOrderRequest $request): JsonResponse
    {
        $summary = $this->cartService->getCartSummary();

        if ($summary['items']->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'سبد خرید شما خالی است.'], 422);
        }

        try {
            $order = $this->orderService->createFromCart(
                cart: $summary['cart'],
                userId: auth()->id(),
                shippingAddressId: $request->shipping_address_id,
                shippingMethodId: $request->shipping_method_id,
                customerNote: $request->customer_note
            );

            UserActivityLogService::log('order.create', auth()->user(), $order, "ثبت سفارش #{$order->order_number}", [
                'total' => $order->total,
            ]);

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'redirect' => route('checkout.payment', $order),
            ]);
        } catch (QueryException $e) {
            // BUG-014: raw DB errors must never reach the customer.
            report($e);

            return response()->json(['success' => false, 'message' => 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.'], 500);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function payment(Order $order): View|RedirectResponse
    {
        if ($order->user_id !== auth()->id()) {
            abort(403);
        }

        if ($order->payment_status === 'paid') {
            return redirect()->route('orders.show', $order->order_number);
        }

        return view('checkout.payment', compact('order'));
    }

    public function startPayment(Order $order): JsonResponse
    {
        if ($order->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
        }

        try {
            $result = $this->paymentService->initiatePayment($order);

            if (! ($result['success'] ?? true)) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'درگاه پرداخت در دسترس نیست.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'redirect_url' => $result['redirect_url'],
            ]);
        } catch (QueryException $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.'], 500);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function paymentCallback(Request $request): View|RedirectResponse
    {
        $gateway = $request->get('gateway', config('rental.payment.gateway', 'mock'));

        $result = $this->paymentService->handleCallback($request->all(), $gateway);

        if ($result['success']) {
            if (! empty($result['order']?->user_id)) {
                $this->cartService->clearUserCart($result['order']->user_id);

                UserActivityLogService::log(
                    'order.paid',
                    User::find($result['order']->user_id),
                    $result['order'],
                    "پرداخت موفق سفارش #{$result['order']->order_number}",
                    ['total' => $result['order']->total]
                );
            }
            if (auth()->check()) {
                $this->cartService->clearCart();
            }

            // A rental order has no order_items -- it is a reservation, not a
            // basket -- so it must not land on the shop's success page. Send
            // it back to its application, where the rest of the chain lives.
            $rentalApplication = $result['order']
                ? RentalApplication::where('order_id', $result['order']->id)->first()
                : null;

            if ($rentalApplication) {
                // C-15: the reservation was created by PaymentService once the
                // gateway result was independently verified -- under a product
                // lock, with availability re-checked inside it, idempotently.
                // Nothing is created here; this only reports the outcome.
                app(RentalChainOrchestrator::class)
                    ->advance($rentalApplication, 'payment verified');

                if ($rentalApplication->fresh()->reservation === null) {
                    // The payment is real and stays recorded. No automatic
                    // refund is issued: no refund rule has been decided (policy
                    // gate), and inventing one would move real money on a guess.
                    // The conflict is already audited; operations reconcile it.
                    return redirect()
                        ->route('rental.applications.show', $rentalApplication)
                        ->with('error', 'پرداخت شما انجام شد، اما ثبت نهایی رزرو در این بازه ممکن نشد. تیم پشتیبانی درخواست شما را بررسی می‌کند.');
                }

                return redirect()
                    ->route('rental.applications.show', $rentalApplication)
                    ->with('success', 'پرداخت با موفقیت انجام شد.');
            }

            $result['order']?->loadMissing(['items.product']);

            return view('checkout.payment-success', [
                'order' => $result['order'],
                'tracking_code' => $result['tracking_code'],
            ]);
        }

        $isCancelled = in_array(strtoupper((string) $request->get('Status')), ['CANCELLED', 'CANCELED', 'CANCEL']);

        return view('checkout.payment-failed', [
            'message' => $result['message'],
            'cancelled' => $isCancelled,
        ]);
    }
}
