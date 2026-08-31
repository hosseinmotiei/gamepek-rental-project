<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class CartService
{
    public function __construct(private InventoryService $inventoryService)
    {
    }

    public function getOrCreateCart(): Cart
    {
        if (Auth::check()) {
            $cart = Cart::firstOrCreate(['user_id' => Auth::id()]);

            // Merge guest cart if exists
            $sessionId = Session::get('cart_session_id');
            if ($sessionId) {
                $this->mergeGuestCart($cart, $sessionId);
                Session::forget('cart_session_id');
            }

            return $cart;
        }

        $sessionId = Session::get('cart_session_id');
        if (!$sessionId) {
            $sessionId = uniqid('guest_', true);
            Session::put('cart_session_id', $sessionId);
        }

        return Cart::firstOrCreate(['session_id' => $sessionId]);
    }

    public function addItem(int $productId, int $quantity = 1, ?array $selectedOptions = null): CartItem
    {
        $product = Product::active()->find($productId);

        if (!$product) {
            throw new \Exception('محصول پیدا نشد.', 404);
        }

        if ($product->effective_price === null) {
            throw new \Exception('قیمت این محصول هنوز ثبت نشده است.', 422);
        }

        if (!$product->isInStock()) {
            throw new \Exception('موجودی این محصول کافی نیست.', 422);
        }

        [$selectedOptions, $optionsPriceModifier] = $this->normalizeSelectedOptions($product, $selectedOptions);
        $optionsHash = $this->hashSelectedOptions($selectedOptions);

        $cart = $this->getOrCreateCart();

        // Matches on product_id AND options_hash, not just product_id -- the
        // same product with two different variant selections (e.g. two GTA
        // VI pre-order editions) must stay as separate cart lines, while a
        // product with no options keeps the exact old merge-by-product
        // behavior (options_hash is constant for it).
        $item = $cart->items()->where('product_id', $productId)->where('options_hash', $optionsHash)->first();

        if ($item) {
            $newQty = $item->quantity + $quantity;
            $this->inventoryService->assertPhysicalStockAvailable($product, $newQty);
            $item->update(['quantity' => $newQty]);
        } else {
            $this->inventoryService->assertPhysicalStockAvailable($product, $quantity);

            $item = CartItem::create([
                'cart_id'    => $cart->id,
                'product_id' => $productId,
                'quantity'   => $quantity,
                'selected_options' => $selectedOptions,
                'options_hash' => $optionsHash,
                'options_price_modifier' => $optionsPriceModifier,
            ]);
        }

        return $item->load('product');
    }

    /**
     * Only accepts options the product actually defines (group title ->
     * one of that group's own value labels) -- silently drops anything
     * else, so a tampered/stale client request can't attach an arbitrary
     * label to an order, or an arbitrary price_modifier along with it.
     * Returns [normalized selections (null if the product has no option
     * groups at all, keeping options_hash constant for ordinary products),
     * sum of the selected values' own price_modifier].
     */
    private function normalizeSelectedOptions(Product $product, ?array $selectedOptions): array
    {
        $groups = $product->optionGroups()->with('values')->get();
        if ($groups->isEmpty()) {
            return [null, 0];
        }

        $normalized = [];
        $modifierTotal = 0;

        foreach ($groups as $group) {
            $chosen = $selectedOptions[$group->title] ?? null;
            $matched = $group->values->firstWhere('label', $chosen);

            if (!$matched) {
                $matched = $group->values->firstWhere('is_default', true) ?? $group->values->first();
            }

            if ($matched) {
                $normalized[$group->title] = $matched->label;
                $modifierTotal += (int) $matched->price_modifier;
            }
        }

        return [$normalized ?: null, $modifierTotal];
    }

    private function hashSelectedOptions(?array $selectedOptions): string
    {
        if (empty($selectedOptions)) {
            return md5('');
        }
        ksort($selectedOptions);
        return md5(json_encode($selectedOptions));
    }

    public function updateItem(int $cartItemId, int $quantity): CartItem
    {
        $cart = $this->getOrCreateCart();
        $item = $cart->items()->find($cartItemId);

        if (!$item) {
            throw new \Exception('آیتم سبد خرید پیدا نشد.', 404);
        }

        if ($quantity <= 0) {
            $item->delete();
            return $item;
        }

        $product = $item->product;
        if (!$product) {
            $item->delete();
            throw new \Exception('این محصول دیگر در دسترس نیست.', 422);
        }

        // BUG-012: addItem() only ever adds active products (Product::active()),
        // but a product can be deactivated after it's already in the cart --
        // updateItem() must reject the same way, not just the final checkout gate.
        if (!$product->is_active) {
            throw new \Exception('این محصول دیگر در دسترس نیست.', 422);
        }

        if ($product->effective_price === null) {
            throw new \Exception('قیمت این محصول هنوز ثبت نشده است.', 422);
        }

        $this->inventoryService->assertPhysicalStockAvailable($product, $quantity);

        $item->update(['quantity' => $quantity]);
        return $item->fresh()->load('product');
    }

    public function removeItem(int $cartItemId): void
    {
        $cart = $this->getOrCreateCart();
        $item = $cart->items()->find($cartItemId);

        if (!$item) {
            throw new \Exception('آیتم سبد خرید پیدا نشد.', 404);
        }

        $item->delete();
    }

    public function applyCoupon(string $code): Coupon
    {
        $coupon = Coupon::where('code', strtoupper($code))->first();

        if (!$coupon || !$coupon->isValid()) {
            throw new \Exception('کد تخفیف نامعتبر یا منقضی شده است.', 422);
        }

        // Per-user-limited coupons require an authenticated user to enforce the limit
        if ($coupon->per_user_limit && !Auth::check()) {
            throw new \Exception('برای استفاده از این کد تخفیف باید وارد حساب کاربری خود شوید.', 401);
        }

        // Check per-user limit for authenticated users
        if ($coupon->per_user_limit && Auth::check()) {
            $usedCount = $coupon->usages()->where('user_id', Auth::id())->count();
            if ($usedCount >= $coupon->per_user_limit) {
                throw new \Exception('شما قبلاً از این کد تخفیف استفاده کرده‌اید.', 422);
            }
        }

        $cart = $this->getOrCreateCart();
        $cart->update(['coupon_id' => $coupon->id]);

        return $coupon;
    }

    public function removeCoupon(): void
    {
        $cart = $this->getOrCreateCart();
        $cart->update(['coupon_id' => null]);
    }

    public function clearCart(): void
    {
        $cart = $this->getOrCreateCart();
        $cart->items()->delete();
        $cart->update(['coupon_id' => null]);
        Session::forget('cart_session_id');
    }

    public function clearUserCart(int $userId): void
    {
        $cart = Cart::where('user_id', $userId)->first();
        if (!$cart) {
            return;
        }
        $cart->items()->delete();
        $cart->update(['coupon_id' => null]);
    }

    public function getCartSummary(): array
    {
        $cart = $this->getOrCreateCart();
        $cart->load(['items.product', 'coupon']);

        $items = $cart->items->filter(fn ($item) => $item->product !== null)->values();
        $cart->setRelation('items', $items);

        $subtotal = $items->sum(fn ($item) => $item->line_total);
        $discount = 0;

        if ($cart->coupon) {
            $discount = $cart->coupon->calculateDiscount($subtotal);
        }

        return [
            'cart'       => $cart,
            'items'      => $items,
            'subtotal'   => $subtotal,
            'discount'   => $discount,
            'items_count' => $items->sum('quantity'),
        ];
    }

    private function mergeGuestCart(Cart $userCart, string $sessionId): void
    {
        $guestCart = Cart::where('session_id', $sessionId)->with('items.product')->first();

        if (!$guestCart) return;

        foreach ($guestCart->items as $guestItem) {
            $product = $guestItem->product;
            if (!$product) continue;

            // Match on options_hash too -- otherwise merging would combine
            // quantities across two different variant selections of the
            // same product into one line, silently dropping which variant
            // was which.
            $existingItem = $userCart->items()
                ->where('product_id', $guestItem->product_id)
                ->where('options_hash', $guestItem->options_hash)
                ->first();
            $mergedQty    = ($existingItem ? $existingItem->quantity : 0) + $guestItem->quantity;

            // Cap merged quantity at available stock
            if ($product->stock_quantity > 0) {
                $mergedQty = min($mergedQty, $product->stock_quantity);
            }

            // Enforce the same per-item quantity maximum as the Add/Update cart request contract
            $mergedQty = min($mergedQty, config('rental.cart.max_quantity_per_item', 10));

            if ($mergedQty <= 0) continue;

            if ($existingItem) {
                $existingItem->update(['quantity' => $mergedQty]);
            } else {
                CartItem::create([
                    'cart_id'    => $userCart->id,
                    'product_id' => $guestItem->product_id,
                    'quantity'   => $mergedQty,
                    'selected_options' => $guestItem->selected_options,
                    'options_hash' => $guestItem->options_hash,
                    'options_price_modifier' => $guestItem->options_price_modifier,
                ]);
            }
        }

        $guestCart->delete();
    }
}
