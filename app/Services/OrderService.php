<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingMethod;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private InventoryService $inventoryService,
    ) {}

    public function createFromCart(
        Cart $cart,
        int $userId,
        ?int $shippingAddressId,
        ?int $shippingMethodId,
        ?string $customerNote
    ): Order {
        $cart->load(['items.product', 'coupon']);

        $validItems = $cart->items->filter(fn ($item) => $item->product !== null)->values();
        $cart->setRelation('items', $validItems);

        if ($validItems->isEmpty()) {
            throw new \Exception('سبد خرید شما خالی است.', 422);
        }

        return DB::transaction(function () use (
            $cart, $userId, $shippingAddressId, $shippingMethodId, $customerNote
        ) {
            // BUG-012: the cart's eager-loaded $item->product is a
            // pre-transaction snapshot -- a product (or its category) can
            // be deactivated after it was added to the cart but before
            // checkout. Re-fetch each product locked and authoritative
            // inside this transaction, replacing the stale relation, so
            // every downstream read below sees only currently-purchasable
            // products. Sorted by product_id first so concurrent checkouts
            // referencing overlapping products always lock in the same
            // order, avoiding a new deadlock risk.
            $sortedItems = $cart->items->sortBy('product_id')->values();

            foreach ($sortedItems as $item) {
                $product = Product::where('id', $item->product_id)
                    ->with('category')
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    throw new \Exception('یکی از محصولات سبد خرید دیگر موجود نیست.', 422);
                }
                if (! $product->is_active) {
                    throw new \Exception("محصول «{$product->title_fa}» دیگر موجود نیست.", 422);
                }
                if (! $product->category || ! $product->category->is_active) {
                    throw new \Exception("محصول «{$product->title_fa}» دیگر موجود نیست.", 422);
                }

                $item->setRelation('product', $product);
            }

            $subtotal = 0;

            foreach ($cart->items as $item) {
                if (! $item->product) {
                    continue;
                }

                $effectivePrice = $item->product->effective_price;
                if ($effectivePrice === null) {
                    throw new \Exception('قیمت این محصول هنوز ثبت نشده است.', 422);
                }
                // Same formula as CartItem::getEffectiveUnitPriceAttribute() --
                // the option modifier was already snapshotted onto the cart
                // line when it was added, so it's never recomputed live here.
                $effectivePrice = max(0, $effectivePrice + (int) ($item->options_price_modifier ?? 0));

                $subtotal += $effectivePrice * $item->quantity;
            }

            // Calculate shipping
            $shippingCost = 0;
            if ($shippingMethodId && $cart->hasPhysicalItems()) {
                $shippingMethod = ShippingMethod::find($shippingMethodId);
                $shippingCost = $shippingMethod?->base_cost ?? 0;
            }

            // Calculate discount
            $discountTotal = 0;
            if ($cart->coupon) {
                $discountTotal = $cart->coupon->calculateDiscount($subtotal);
            }

            $total = max(0, $subtotal - $discountTotal + $shippingCost);

            $shippingAddressSnapshot = $this->makeShippingAddressSnapshot($shippingAddressId);

            // Create order (BUG-015: bounded retry on order_number collision only)
            $order = Order::createWithUniqueNumber([
                'user_id' => $userId,
                'status' => 'pending_payment',
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'shipping_cost' => $shippingCost,
                'total' => $total,
                'coupon_id' => $cart->coupon_id,
                'shipping_address_id' => $shippingAddressId,
                'shipping_address_snapshot' => $shippingAddressSnapshot,
                'shipping_method_id' => $shippingMethodId,
                'customer_note' => $customerNote,
            ]);

            // Create order items
            foreach ($cart->items as $item) {
                if (! $item->product) {
                    continue;
                }

                $effectivePrice = $item->product->effective_price;
                if ($effectivePrice === null) {
                    throw new \Exception('قیمت این محصول هنوز ثبت نشده است.', 422);
                }
                $optionsModifier = (int) ($item->options_price_modifier ?? 0);
                $effectivePrice = max(0, $effectivePrice + $optionsModifier);

                $unitPrice = $item->product->price ?? $effectivePrice;

                // One OrderItem per cart line. The snapshot columns are what
                // order history renders from -- never a live join to the
                // mutable product row -- so a later edit or soft-delete of the
                // catalog item cannot retroactively change a past order.
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'product_title_snapshot' => $item->product->title_fa,
                    'product_sku_snapshot' => $item->product->sku,
                    'product_type_snapshot' => null,
                    'product_image_snapshot' => $item->product->main_image,
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                    'sale_price' => $item->product->sale_price,
                    'total_price' => $effectivePrice * $item->quantity,
                    'selected_options' => $item->selected_options,
                    'options_price_modifier' => $optionsModifier,
                ]);
            }

            // BUG-008: without clearing the cart here, a repeated "place
            // order" click (double-submit, back button, retry before
            // payment) re-runs this same method against the same cart
            // items, creating another pending order for what the user still
            // perceives as one purchase.
            $cart->items()->delete();
            $cart->update(['coupon_id' => null]);

            return $order;
        });
    }

    public function markAsPaid(Order $order, ?string $trackingCode = null): void
    {
        DB::transaction(function () use ($order) {
            // BUG-001: a stale in-memory $order->payment_status read is not
            // sufficient against replay (callback retries, admin double
            // click, concurrent requests) -- re-fetch and lock the
            // authoritative row inside this same transaction, and treat it
            // (not the possibly-stale $order argument) as the source of
            // truth for every paid side effect below.
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked || $locked->payment_status === 'paid') {
                if ($locked) {
                    $order->setRawAttributes($locked->getAttributes(), true);
                }

                return;
            }

            $locked->load(['items.product', 'user.cart.items']);

            // BUG-006: the BUG-001 Order lock above does not protect physical
            // inventory across two DIFFERENT orders racing for the same
            // Product. $item->product here is a plain eager-loaded, unlocked
            // read -- authoritative stock must come from a fresh
            // lockForUpdate() fetch, checked and held BEFORE any mutation.
            // Delegated to InventoryService (Sprint 2 Task 4), but still
            // executed inside this same transaction -- OrderService remains
            // the transaction owner.
            $lockedProducts = $this->inventoryService->lockAndValidateStock($locked->items);

            $locked->update([
                'status' => 'paid',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);

            foreach ($locked->items as $item) {
                // Decrement stock using the row already locked and validated
                // above -- never the stale $item->product.
                if ($item->product) {
                    $this->inventoryService->decrementStock($lockedProducts[$item->product_id], $item->quantity);
                }
            }

            // Record coupon usage
            if ($locked->coupon_id) {
                CouponUsage::create([
                    'coupon_id' => $locked->coupon_id,
                    'user_id' => $locked->user_id,
                    'order_id' => $locked->id,
                ]);
                $locked->coupon->increment('used_count');
            }

            // Clear the user's cart
            $locked->user->cart?->items()->delete();

            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function markAsFailed(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->update([
                'status' => 'failed',
                'payment_status' => 'failed',
            ]);

        });
    }

    public function cancelOrder(Order $order, string $targetStatus = 'cancelled'): void
    {
        if (! in_array($targetStatus, ['cancelled', 'refunded'], true)) {
            throw new \Exception('وضعیت هدف نامعتبر است.', 422);
        }

        if (! in_array($order->status, ['pending_payment', 'paid', 'processing'])) {
            throw new \Exception('این سفارش قابل لغو نیست.', 422);
        }

        DB::transaction(function () use ($order, $targetStatus) {
            // Re-fetch and lock the authoritative row inside this transaction
            // so a concurrent double-cancel can't restore stock twice.
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, ['pending_payment', 'paid', 'processing'])) {
                if ($locked) {
                    $order->setRawAttributes($locked->getAttributes(), true);
                }

                return;
            }

            $wasPaid = $locked->payment_status === 'paid';

            $update = ['status' => $targetStatus];

            // If payment was already collected, flag it as refunded for accurate reporting
            if ($wasPaid) {
                $update['payment_status'] = 'refunded';
            }

            $locked->update($update);

            $locked->load('items.product');

            // BUG-007: markAsPaid() decrements physical stock; cancelling a
            // paid order must restore it, or every paid-then-cancelled unit
            // is permanently lost from stock_quantity. Only orders that were
            // actually paid had stock decremented -- pending_payment orders
            // never touched stock and must not be restored. Delegated to
            // InventoryService (Sprint 2 Task 4), but still executed inside
            // this same transaction -- OrderService remains the transaction
            // owner.
            if ($wasPaid) {
                $this->inventoryService->restoreStock($locked->items);
            }

            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Sprint 3 Task 4.5: reconciles internal order state after a Pardakht
     * Novin Reverse has already succeeded at the gateway (called from
     * PaymentService::reversePayment() -- see that method's docblock).
     *
     * Deliberately a NEW, additive method rather than an extension of
     * cancelOrder(): cancelOrder() is also used by customer self-cancel and
     * the admin status dropdown, so extending it would silently change
     * behavior for every other caller too.
     *
     * Idempotent: no-ops if payment_status is already 'refunded' (mirrors
     * markAsPaid()'s own "already paid" no-op shape), so running this twice
     * never restores stock twice, disables codes twice, or re-touches an
     * already-reconciled order.
     */
    public function refundOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked || $locked->payment_status === 'refunded') {
                if ($locked) {
                    $order->setRawAttributes($locked->getAttributes(), true);
                }

                return;
            }

            $locked->update([
                'status' => 'refunded',
                'payment_status' => 'refunded',
            ]);

            $locked->load('items.product');

            // Stock: an order eligible for Reverse was
            // necessarily paid (markAsPaid() already decremented stock for
            // it), so it is always restored here -- same InventoryService
            // call cancelOrder() already uses for its own wasPaid case.
            $this->inventoryService->restoreStock($locked->items);

            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Sprint 2 Task 2: consolidates the fulfillment-status transitions that
     * previously bypassed OrderService entirely (a raw $order->status = ...;
     * $order->save() inside Admin\OrderController::updateStatus()'s generic
     * branch). Mirrors the lock-then-check idempotency shape already used by
     * markAsPaid/cancelOrder rather than trusting the caller's possibly-stale
     * $order instance.
     */
    public function markProcessing(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            // Idempotent no-op if this transition was already applied (e.g. a
            // repeated admin submission of the same target status).
            if ($locked->status === 'processing') {
                $order->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            if ($locked->status !== 'paid') {
                throw new \Exception('این سفارش را نمی‌توان به «در حال پردازش» تغییر داد.', 422);
            }

            $locked->update(['status' => 'processing']);

            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function markShipped(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            if ($locked->status === 'shipped') {
                $order->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            if (! in_array($locked->status, ['processing', 'paid'], true)) {
                throw new \Exception('این سفارش را نمی‌توان به «ارسال شده» تغییر داد.', 422);
            }

            $update = ['status' => 'shipped'];
            if (! $locked->shipped_at) {
                $update['shipped_at'] = now();
            }

            $locked->update($update);

            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function markDelivered(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            if ($locked->status === 'delivered') {
                $order->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            if ($locked->status !== 'shipped') {
                throw new \Exception('این سفارش را نمی‌توان به «تحویل داده شده» تغییر داد.', 422);
            }

            $update = ['status' => 'delivered'];
            if (! $locked->delivered_at) {
                $update['delivered_at'] = now();
            }

            $locked->update($update);

            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function makeShippingAddressSnapshot(?int $shippingAddressId): ?array
    {
        if (! $shippingAddressId) {
            return null;
        }

        $address = Address::find($shippingAddressId);

        if (! $address) {
            return null;
        }

        return [
            'receiver_name' => $address->receiver_name,
            'receiver_mobile' => $address->receiver_mobile,
            'province' => $address->province,
            'city' => $address->city,
            'district' => $address->district,
            'address_line' => $address->address_line,
            'postal_code' => $address->postal_code,
            'plaque' => $address->plaque,
            'unit' => $address->unit,
            'full_address' => $address->full_address,
        ];
    }
}
