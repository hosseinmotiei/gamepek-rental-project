<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Services\ActivityLogService;
use App\Services\Audit\AuditLogger;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function index(Request $request)
    {
        abort_if(! auth()->user()->can('view_payments'), 403);

        $query = PaymentTransaction::with(['order', 'user'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('gateway')) {
            $query->where('gateway', $request->gateway);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('tracking_code', 'like', "%{$search}%")
                    ->orWhere('authority', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$search}%"))
                    ->orWhereHas('user', fn ($u) => $u->where('full_name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%"));
            });
        }

        $transactions = $query->paginate(20)->withQueryString();

        return view('admin.payments.index', compact('transactions'));
    }

    public function show(PaymentTransaction $transaction)
    {
        abort_if(! auth()->user()->can('view_payments'), 403);

        $transaction->load(['order.items', 'user']);

        return view('admin.payments.show', compact('transaction'));
    }

    /**
     * Manually approve a pending transaction: this does NOT fake a gateway
     * callback. It records the admin's decision on the transaction itself,
     * then delegates the paid-order side effects (stock/coupon/cart/digital
     * code) to the same authoritative, already-hardened OrderService lifecycle
     * a real gateway callback uses -- no logic is duplicated here.
     */
    public function approve(Request $request, PaymentTransaction $transaction)
    {
        abort_if(! auth()->user()->can('mark_payment_status'), 403);

        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $locked = DB::transaction(function () use ($transaction) {
            $row = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $row || $row->status !== 'pending') {
                return null; // Not eligible -- already resolved by another action/callback.
            }

            $row->update([
                'status' => 'success',
                'paid_at' => now(),
            ]);

            return $row;
        });

        if (! $locked) {
            return back()->with('error', 'این تراکنش دیگر در وضعیت «در انتظار» نیست و قابل تأیید نیست.');
        }

        $order = $transaction->order()->first();
        if ($order) {
            $this->orderService->markAsPaid($order, $locked->tracking_code);
        }

        ActivityLogService::log('payment.manual_approve', $locked, "تأیید دستی تراکنش #{$locked->id}", [
            'order_id' => $order?->id, 'reason' => $request->input('reason'), 'before' => 'pending', 'after' => 'success',
        ]);

        // Written alongside the activity-log entry, not instead of it: the
        // admin feed keeps its shape, and the audit trail gains actor,
        // resource, result and correlation id. A manual approval deliberately
        // bypasses gateway verification, so it must be traceable to a person.
        AuditLogger::log(
            action: 'payment.manual_approve',
            resourceType: 'PaymentTransaction',
            resourceId: $locked->id,
            context: [
                'order_id' => $order?->id,
                'reason' => $request->input('reason'),
                'bypassed_gateway_verification' => true,
            ],
        );

        return redirect()->route('admin.payments.show', $transaction)
            ->with('success', 'پرداخت با موفقیت تأیید شد.');
    }

    /**
     * Manually reject a pending transaction. This only marks the transaction
     * attempt as failed -- it never touches an order that is already paid,
     * since only 'pending' transactions are eligible in the first place.
     */
    public function reject(Request $request, PaymentTransaction $transaction)
    {
        abort_if(! auth()->user()->can('mark_payment_status'), 403);

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'وارد کردن دلیل رد پرداخت الزامی است.',
        ]);

        $locked = DB::transaction(function () use ($transaction) {
            $row = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $row || $row->status !== 'pending') {
                return null; // Not eligible -- already resolved by another action/callback.
            }

            $row->update(['status' => 'failed']);

            return $row;
        });

        if (! $locked) {
            return back()->with('error', 'این تراکنش دیگر در وضعیت «در انتظار» نیست و قابل رد کردن نیست.');
        }

        ActivityLogService::log('payment.manual_reject', $locked, "رد دستی تراکنش #{$locked->id}", [
            'order_id' => $locked->order_id, 'reason' => $request->input('reason'), 'before' => 'pending', 'after' => 'failed',
        ]);

        return redirect()->route('admin.payments.show', $transaction)
            ->with('success', 'پرداخت رد شد.');
    }
}
