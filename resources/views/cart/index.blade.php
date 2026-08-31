@extends('layouts.app')

@section('title', 'گیم‌پک | سبد خرید')

@section('content')

{{-- Mobile Sticky Header --}}
<div class="md:hidden sticky top-[104px] z-30 w-full bg-white border-b border-gray-100 flex items-center px-4 py-3.5 gap-3 shadow-sm">
    <h1 class="font-bold text-gray-800 text-base flex-1">سبد خرید</h1>
    @if(!$items->isEmpty())
    <span class="text-xs font-bold text-brandBlue bg-blue-50 px-2 py-1 rounded-md">{{ $items->count() }} کالا</span>
    @endif
</div>

<div class="max-w-[1400px] mx-auto px-4 py-4 md:py-8">

    @if($items->isEmpty())
    <div class="flex flex-col items-center justify-center py-20 text-center">
        <i class="fa-solid fa-cart-shopping text-6xl text-gray-200 mb-6"></i>
        <h2 class="text-xl font-bold text-gray-800 mb-2">{{ setting('checkout.cart_empty_message', 'سبد خرید شما خالی است') }}</h2>
        <p class="text-sm text-gray-500 mb-8">برای خرید، محصولات دلخواه خود را اضافه کنید</p>
        <a href="{{ route('products.index') }}" class="bg-brandBlue text-white font-bold py-3 px-8 rounded-xl hover:bg-blue-600 transition-colors">{{ setting('checkout.cart_cta_text', 'رفتن به فروشگاه') }}</a>
    </div>
    @else
    {{-- Desktop Title --}}
    <div class="hidden md:flex items-center gap-2 mb-6">
        <h1 class="text-xl font-bold text-gray-800 border-b-2 border-flashRed pb-2">سبد خرید شما</h1>
        <span class="text-sm font-bold text-white bg-flashRed px-2 py-0.5 rounded-full mb-1">{{ $items->count() }}</span>
    </div>

    <div class="flex flex-col md:flex-row gap-6 items-start">

        {{-- Cart Items --}}
        <div class="flex-1 w-full flex flex-col gap-4" id="cart-items">
            @foreach($items as $item)
            @php
                $product = $item->product;
                $unitPrice = $product?->effective_price ?? 0;
                $lineTotal = $item->line_total;
            @endphp
            @continue(!$product)
            <div class="bg-white rounded-2xl border border-gray-200 p-4 md:p-6 shadow-sm flex flex-col md:flex-row gap-4 md:gap-6 relative" id="cart-item-{{ $item->id }}">

                {{-- Image --}}
                <a href="{{ route('products.show', $product->slug) }}" class="w-full md:w-36 h-40 md:h-36 shrink-0 flex items-center justify-center p-2">
                    <img src="{{ media_url($product->main_image, 'https://placehold.co/300x300/F5F5F5/111?text=PS5') }}"
                         alt="{{ $product->title_fa }}"
                         class="w-full h-full object-contain mix-blend-multiply rounded-xl">
                </a>

                {{-- Details --}}
                <div class="flex flex-col flex-1">
                    <a href="{{ route('products.show', $product->slug) }}"
                       class="font-bold text-gray-800 text-sm md:text-base leading-relaxed mb-1 hover:text-brandBlue transition-colors block">
                        {{ $product->title_fa }}
                    </a>

                    @if(!empty($item->selected_options))
                    <div class="flex flex-wrap gap-1.5 mb-3">
                        @foreach($item->selected_options as $groupTitle => $valueLabel)
                        <span class="text-[10px] bg-brandLightBlue text-brandBlue font-medium px-2 py-0.5 rounded-lg">{{ $groupTitle }}: {{ $valueLabel }}</span>
                        @endforeach
                    </div>
                    @else
                    <div class="mb-2"></div>
                    @endif

                    <div class="flex flex-col gap-2 mb-4">
                        <div class="flex items-center gap-2 text-xs text-gray-600">
                            <i class="fa-solid fa-shield-halved text-gray-400 w-4 text-center"></i>
                            <span>گارانتی ۱۸ ماهه گیم‌پک</span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-gray-600">
                            <i class="fa-solid fa-store text-gray-400 w-4 text-center"></i>
                            <span>فروشنده: گیم‌پک</span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-green-600 font-medium">
                            <i class="fa-solid fa-circle-check w-4 text-center"></i>
                            <span>موجود در انبار گیم‌پک</span>
                        </div>
                    </div>

                    {{-- Qty & Price Row --}}
                    <div class="flex items-center justify-between gap-2 mt-auto flex-wrap">
                        {{-- Quantity Adjuster --}}
                        <div class="flex items-center gap-2 bg-white border border-gray-200 rounded-xl px-2.5 py-1.5 shadow-sm shrink-0">
                            <button onclick="updateQty({{ $item->id }}, {{ $item->quantity + 1 }})"
                                    class="text-brandBlue hover:text-blue-700 w-7 h-7 flex items-center justify-center transition-colors">
                                <i class="fa-solid fa-plus text-xs"></i>
                            </button>
                            <span id="qty-{{ $item->id }}" class="font-bold text-brandBlue text-sm min-w-[24px] text-center">{{ persian_number($item->quantity) }}</span>
                            <button onclick="updateQty({{ $item->id }}, {{ $item->quantity - 1 }})"
                                    class="text-red-500 hover:text-red-600 w-7 h-7 flex items-center justify-center transition-colors">
                                @if($item->quantity === 1)
                                <i class="fa-regular fa-trash-can text-xs"></i>
                                @else
                                <i class="fa-solid fa-minus text-xs"></i>
                                @endif
                            </button>
                        </div>

                        {{-- Price --}}
                        <div class="flex flex-col items-end min-w-0">
                            @if($product->sale_price)
                            <div class="flex items-center gap-1.5 mb-0.5 flex-wrap justify-end">
                                <span class="text-xs text-gray-400 line-through decoration-red-400">{{ persian_number(($product->price ?? $unitPrice) * $item->quantity) }}</span>
                                <span class="text-[10px] font-bold text-white bg-flashRed px-1.5 py-0.5 rounded-full shrink-0">{{ persian_number($product->discount_percent) }}٪</span>
                            </div>
                            <div class="text-base md:text-xl font-bold text-brandDark flex items-baseline gap-1">
                                <span id="price-{{ $item->id }}">{{ persian_number($lineTotal) }}</span>
                                <span class="text-xs font-normal text-gray-500">تومان</span>
                            </div>
                            @if($product->price && $product->sale_price)
                            <span class="text-[10px] text-flashRed mt-0.5 whitespace-nowrap">{{ persian_number(($product->price - $product->sale_price) * $item->quantity) }} تومان تخفیف</span>
                            @endif
                            @else
                            <div class="text-base md:text-xl font-bold text-brandDark flex items-baseline gap-1">
                                <span id="price-{{ $item->id }}">{{ persian_number($lineTotal) }}</span>
                                <span class="text-xs font-normal text-gray-500">تومان</span>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- Order Summary (Sticky Sidebar) --}}
        <div class="w-full md:w-[360px] shrink-0 sticky top-[100px]">
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm flex flex-col gap-4">

                {{-- Coupon --}}
                <div>
                    <div class="flex gap-2">
                        <input type="text" id="coupon-input" placeholder="کد تخفیف"
                               class="flex-1 bg-gray-100 rounded-xl px-4 py-2.5 text-sm outline-none focus:bg-white border border-transparent focus:border-brandBlue transition-all">
                        <button onclick="applyCoupon()" class="bg-gray-800 text-white text-sm font-bold px-4 py-2.5 rounded-xl hover:bg-gray-900 transition-colors whitespace-nowrap">اعمال</button>
                    </div>
                    <div id="coupon-message" class="text-xs mt-2 hidden"></div>
                </div>

                <div class="w-full h-px bg-gray-100"></div>

                {{-- Price Rows --}}
                <div class="flex justify-between items-center text-sm text-gray-600">
                    <span>قیمت کالاها ({{ persian_number($items->count()) }})</span>
                    <span>{{ persian_number($subtotal) }} <span class="text-xs">تومان</span></span>
                </div>

                @if($discount > 0)
                <div class="flex justify-between items-center text-sm text-flashRed">
                    <span>سود شما از خرید</span>
                    <span>({{ persian_number($discount) }}) <span class="text-xs">تومان</span></span>
                </div>
                @endif

                <div class="flex justify-between items-center text-sm text-gray-600">
                    <span>هزینه ارسال</span>
                    <span class="text-brandBlue text-xs font-bold">در مرحله بعد</span>
                </div>

                <div class="w-full h-px bg-gray-100"></div>

                <div class="flex justify-between items-center">
                    <span class="text-sm font-bold text-gray-800">جمع سبد خرید</span>
                    <div class="text-xl font-black text-brandDark tracking-tight">
                        {{ persian_number($subtotal - $discount) }} <span class="text-xs font-normal text-gray-500">تومان</span>
                    </div>
                </div>

                @auth
                <a href="{{ route('checkout.shipping') }}"
                   class="block w-full bg-flashRed text-white font-bold text-sm py-4 rounded-xl text-center shadow-lg shadow-red-500/20 hover:bg-red-600 transition-colors">
                    تایید و تکمیل سفارش
                </a>
                @else
                <a href="{{ route('auth.login') }}"
                   class="block w-full bg-brandBlue text-white font-bold text-sm py-4 rounded-xl text-center shadow-lg shadow-blue-500/20 hover:bg-blue-600 transition-colors">
                    ورود برای ادامه خرید
                </a>
                @endauth

                {{-- Trust Badges --}}
                <div class="flex items-center justify-center gap-4 text-[10px] text-gray-400 pt-2 border-t border-gray-100">
                    <span class="flex items-center gap-1"><i class="fa-solid fa-shield-halved text-green-500"></i> پرداخت امن</span>
                    <span class="flex items-center gap-1"><i class="fa-solid fa-rotate-left text-blue-500"></i> ضمانت بازگشت</span>
                    <span class="flex items-center gap-1"><i class="fa-solid fa-certificate text-yellow-500"></i> اصالت کالا</span>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

@endsection

@push('scripts')
<script>
function updateQty(itemId, qty) {
    if (qty < 0) return;
    fetch(`/cart/items/${itemId}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify({ quantity: qty })
    }).then(r=>r.json()).then(d=>{
        if (d.success) {
            if (qty === 0) { document.getElementById(`cart-item-${itemId}`)?.remove(); }
            else { document.getElementById(`qty-${itemId}`).textContent = qty; }
            updateCartBadge(d.items_count);
            location.reload();
        } else { showToast(d.message, 'error'); }
    });
}
function applyCoupon() {
    const code = document.getElementById('coupon-input').value.trim();
    if (!code) return;
    fetch('/cart/coupon', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify({ code })
    }).then(r=>r.json()).then(d=>{
        const msg = document.getElementById('coupon-message');
        msg.classList.remove('hidden');
        if (d.success) { msg.className='text-xs mt-2 text-green-600'; msg.textContent=d.message; location.reload(); }
        else { msg.className='text-xs mt-2 text-red-500'; msg.textContent=d.message; }
    });
}
</script>
@endpush
