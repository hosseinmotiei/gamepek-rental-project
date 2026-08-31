@extends('layouts.app')

@section('title', 'گیم‌پک اجاره | ' . $product->title_fa)

@push('styles')
<style>
    .sticky-tabs { position: sticky; top: 60px; z-index: 30; background: white; }
    @media (min-width: 768px) { .sticky-tabs { top: 72px; } }
    .product-description img { max-width: 100%; height: auto; border-radius: 0.75rem; }
    .product-description { overflow-wrap: anywhere; }
</style>
@endpush

@section('content')
<script>const PRODUCT_ID = {{ $product->id }};</script>

<div class="max-w-[1400px] mx-auto px-0 md:px-4 py-4 md:py-8 pb-32 md:pb-8">

    {{-- Breadcrumb (Desktop) --}}
    <nav class="hidden md:flex items-center gap-2 text-xs text-gray-500 mb-6">
        <a href="{{ route('home') }}" class="hover:text-brandBlue">فروشگاه اینترنتی گیم‌پک</a>
        <i class="fa-solid fa-angle-left text-[10px]"></i>
        <a href="{{ route('products.index') }}" class="hover:text-brandBlue">همه محصولات</a>
        @if($product->category)
        <i class="fa-solid fa-angle-left text-[10px]"></i>
        <a href="{{ route('products.index', ['category' => $product->category->slug]) }}" class="hover:text-brandBlue">{{ $product->category->name_fa }}</a>
        @endif
        <i class="fa-solid fa-angle-left text-[10px]"></i>
        <span class="text-gray-800">{{ $product->title_fa }}</span>
    </nav>

    {{-- Product Top Section --}}
    <div class="flex flex-col lg:flex-row gap-6 lg:gap-8 items-start">

        {{-- 1. Image Gallery --}}
        <div class="w-full lg:w-[400px] shrink-0 flex flex-col items-center">
            {{-- Main Image --}}
            @php
                $images = $product->images ?? [];
                if (empty($images) && $product->main_image) { $images = [$product->main_image]; }
                $mainImage = $images[0] ?? $product->main_image;
                $imageCount = count($images);
            @endphp
            <div class="w-full p-8 md:p-4 flex items-center justify-center relative">
                @if($imageCount > 1)
                <span class="absolute bottom-4 right-4 bg-gray-100 text-gray-600 text-xs px-2 py-1 rounded-lg flex items-center gap-1 z-10">
                    <i class="fa-regular fa-image"></i>
                    <span id="img-counter">۱</span> / <span>{{ persian_number($imageCount) }}</span>
                </span>
                @endif
                @if($product->discount_percent > 0)
                <span class="absolute top-3 left-3 bg-flashRed text-white text-xs font-black px-2 py-1 rounded-lg z-10">{{ $product->discount_percent }}٪</span>
                @endif
                <img id="main-image"
                     src="{{ product_image_url($product, $mainImage) }}"
                     alt="{{ $product->title_fa }}"
                     fetchpriority="high"
                     decoding="async"
                     width="400"
                     height="400"
                     class="w-full max-w-[300px] lg:max-w-[400px] object-contain mix-blend-multiply rounded-xl">
            </div>

            {{-- Thumbnails (Desktop) --}}
            @if($imageCount > 1)
            <div class="hidden md:flex gap-3 w-full px-4 overflow-x-auto hide-scrollbar">
                @foreach($images as $i => $img)
                @if($i < 4)
                <div onclick="switchImage('{{ product_image_url(null, $img) }}', {{ $i + 1 }})"
                     class="w-16 h-16 rounded-xl border-2 {{ $i === 0 ? 'border-brandBlue' : 'border-gray-200 hover:border-gray-400' }} p-1 cursor-pointer shrink-0 transition-colors thumb-btn">
                    <img src="{{ product_image_url(null, $img) }}" alt="{{ $product->title_fa }}" loading="lazy" decoding="async" width="64" height="64" class="w-full h-full object-contain">
                </div>
                @elseif($i === 4)
                <div class="w-16 h-16 rounded-xl border border-gray-200 p-1 cursor-pointer shrink-0 hover:border-gray-400 flex items-center justify-center text-gray-400 text-xl">
                    <i class="fa-solid fa-ellipsis"></i>
                </div>
                @endif
                @endforeach
            </div>
            @endif
        </div>

        {{-- 2. Product Details --}}
        <div class="flex-1 w-full px-4 md:px-0">
            @if($product->category)
            <a href="{{ route('products.index', ['category' => $product->category->slug]) }}"
               class="text-xs text-brandBlue font-bold mb-2 inline-block hover:underline">
                گیم‌پک / {{ $product->category->name_fa }}
            </a>
            @endif

            <div class="flex flex-wrap gap-2 mb-2">
                @if($product->stock_status === 'coming_soon') <span class="text-[10px] bg-purple-100 text-purple-600 font-bold px-2 py-0.5 rounded-full">به زودی</span> @endif
                @if($product->is_flash_sale) <span class="text-[10px] bg-red-100 text-red-600 font-bold px-2 py-0.5 rounded-full">فلش سیل</span> @endif
            </div>

            <h1 class="text-lg md:text-xl font-bold text-gray-800 leading-relaxed mb-1">{{ $product->title_fa }}</h1>
            @if($product->title_en)
            <h2 class="text-xs text-gray-400 mb-4" dir="ltr">{{ $product->title_en }}</h2>
            @endif

            {{-- Variant options, defined per item in the admin panel --}}
            @if($product->optionGroups->isNotEmpty())
            <div class="mb-6 flex flex-col gap-4" id="product-options">
                @foreach($product->optionGroups as $group)
                @php $defaultValue = $group->values->firstWhere('is_default', true) ?? $group->values->first(); @endphp
                <div>
                    <p class="text-xs font-bold text-gray-600 mb-2">{{ $group->title }}</p>
                    <div class="flex flex-wrap gap-2" data-option-group="{{ $group->title }}">
                        @foreach($group->values as $value)
                        <button type="button"
                                onclick="selectProductOption('{{ $group->title }}', '{{ $value->label }}', this)"
                                data-value="{{ $value->label }}"
                                class="option-pill px-4 py-2 rounded-xl border text-xs md:text-sm font-medium transition-colors {{ $defaultValue && $defaultValue->id === $value->id ? 'border-brandBlue text-brandBlue bg-brandLightBlue' : 'border-gray-200 text-gray-700 hover:border-gray-300' }}">
                            {{ $value->label }}
                        </button>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
            <script>
                // groupTitle -> { valueLabel: price_modifier } -- lets JS
                // recompute the total price live without a server round-trip
                // whenever a pill is clicked. The actual charged price is
                // still always recomputed and verified server-side when
                // adding to cart (CartService::normalizeSelectedOptions) --
                // this is a display-only preview.
                window.productOptionModifiers = window.productOptionModifiers || {};
                window.productBasePrice = {{ (int) ($product->sale_price ?: $product->price ?: 0) }};
                window.selectedProductOptions = window.selectedProductOptions || {};

                @foreach($product->optionGroups as $group)
                window.productOptionModifiers[@json($group->title)] = {
                    @foreach($group->values as $value)
                    {{ Illuminate\Support\Js::from($value->label) }}: {{ (int) $value->price_modifier }},
                    @endforeach
                };
                @php $defaultValue = $group->values->firstWhere('is_default', true) ?? $group->values->first(); @endphp
                @if($defaultValue)
                window.selectedProductOptions[@json($group->title)] = @json($defaultValue->label);
                @endif
                @endforeach

                function toPersianPrice(n) {
                    return Math.round(n).toLocaleString('en-US').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
                }

                function updateDisplayedPrice() {
                    let total = window.productBasePrice;
                    Object.keys(window.selectedProductOptions).forEach(groupTitle => {
                        const label = window.selectedProductOptions[groupTitle];
                        const modifier = (window.productOptionModifiers[groupTitle] || {})[label] || 0;
                        total += modifier;
                    });
                    total = Math.max(0, total);
                    const formatted = toPersianPrice(total);
                    ['mobile-final-price', 'desktop-final-price', 'sticky-final-price'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.textContent = formatted;
                    });
                }

                function selectProductOption(groupTitle, valueLabel, el) {
                    window.selectedProductOptions[groupTitle] = valueLabel;
                    const row = el.closest('[data-option-group]');
                    row.querySelectorAll('.option-pill').forEach(btn => {
                        btn.classList.remove('border-brandBlue', 'text-brandBlue', 'bg-brandLightBlue');
                        btn.classList.add('border-gray-200', 'text-gray-700');
                    });
                    el.classList.remove('border-gray-200', 'text-gray-700');
                    el.classList.add('border-brandBlue', 'text-brandBlue', 'bg-brandLightBlue');
                    updateDisplayedPrice();
                }

                // The static server-rendered price above doesn't know about
                // the default option's modifier. Deferred to DOMContentLoaded
                // because this script runs before the desktop buy-box's price
                // element (a later sibling in the DOM) has been parsed yet.
                document.addEventListener('DOMContentLoaded', updateDisplayedPrice);
            </script>
            @endif

            {{-- Mobile Price & Add to Cart --}}
            <div class="lg:hidden">
                @if($product->sale_price && $product->sale_price < $product->price)
                <div class="flex items-center gap-2 mb-1">
                    <span class="bg-flashRed text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">{{ $product->discount_percent }}٪</span>
                    <span class="text-sm text-gray-400 line-through">{{ persian_number($product->price) }}</span>
                </div>
                <div class="flex items-center gap-2 mb-4">
                    <span class="text-2xl font-black text-gray-900" id="mobile-final-price">{{ persian_number($product->sale_price) }}</span>
                    <span class="text-sm text-gray-500">تومان</span>
                </div>
                @elseif($product->price)
                <div class="flex items-center gap-2 mb-4">
                    <span class="text-2xl font-black text-gray-900" id="mobile-final-price">{{ persian_number($product->price) }}</span>
                    <span class="text-sm text-gray-500">تومان</span>
                </div>
                @endif
            </div>
        </div>

        {{-- 3. Desktop Buy Box (Sticky Sidebar) --}}
        <div class="hidden lg:flex w-[340px] shrink-0 flex-col gap-4 sticky top-[140px]">
            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5 flex flex-col">
                <div class="flex items-center justify-between mb-4 border-b border-gray-200 pb-4">
                    <span class="font-bold text-gray-800">فروشنده</span>
                    <span class="text-xs text-brandBlue font-medium">فروشگاه گیم‌پک</span>
                </div>

                <div class="flex items-center gap-3 mb-4">
                    <i class="fa-solid fa-store text-xl text-gray-400"></i>
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-gray-800 text-sm">گیم‌پک</span>
                            <span class="bg-green-100 text-green-700 text-[10px] font-bold px-1.5 rounded">منتخب</span>
                        </div>
                        <span class="text-[10px] text-gray-500 mt-0.5">عملکرد <span class="text-green-600 font-bold">عالی</span></span>
                    </div>
                </div>


                @if($product->isInStock())
                <div class="flex items-center gap-3 mb-4 border-t border-gray-200 pt-4">
                    <i class="fa-solid fa-box-open text-lg text-brandBlue"></i>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-bold text-gray-800">موجود</span>
                        @if(setting('checkout.shipping_origin_text'))
                        <span class="text-[10px] text-gray-500"><i class="fa-solid fa-truck-fast text-brandBlue"></i> {{ setting('checkout.shipping_origin_text') }}</span>
                        @endif
                    </div>
                </div>
                @endif

                <div class="border-t border-gray-200 pt-4 mb-4">
                    @if($product->sale_price && $product->sale_price < $product->price)
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-sm text-gray-400 line-through">{{ persian_number($product->price) }}</span>
                        <span class="bg-flashRed text-white text-[10px] font-bold px-1.5 rounded-full">{{ $product->discount_percent }}٪</span>
                    </div>
                    <div class="flex items-end gap-1">
                        <span class="text-2xl font-black text-gray-900" id="desktop-final-price">{{ persian_number($product->sale_price) }}</span>
                        <span class="text-xs text-gray-600 mb-1">تومان</span>
                    </div>
                    @elseif($product->price)
                    <div class="flex items-end gap-1">
                        <span class="text-2xl font-black text-gray-900" id="desktop-final-price">{{ persian_number($product->price) }}</span>
                        <span class="text-xs text-gray-600 mb-1">تومان</span>
                    </div>
                    @endif
                </div>

                @if($product->isInStock())
                <div class="flex items-center gap-2 border border-gray-200 rounded-xl p-2 mb-3">
                    <button onclick="changeQty(-1)" class="px-3 py-1.5 text-gray-600 hover:bg-gray-100 font-bold text-lg rounded-lg transition-colors">-</button>
                    <span id="qty-display" class="flex-1 text-center font-bold text-gray-800">۱</span>
                    <button onclick="changeQty(1)" class="px-3 py-1.5 text-gray-600 hover:bg-gray-100 font-bold text-lg rounded-lg transition-colors">+</button>
                </div>
                <button onclick="addToCartPage(PRODUCT_ID)" class="w-full bg-brandBlue text-white font-bold py-3.5 rounded-xl hover:bg-blue-600 transition-colors shadow-lg shadow-blue-500/20 text-sm flex justify-center items-center gap-2">
                    <i class="fa-solid fa-cart-shopping"></i> افزودن به سبد خرید
                </button>
                @else
                <button disabled class="w-full bg-gray-200 text-gray-400 font-bold py-3.5 rounded-xl cursor-not-allowed text-sm">ناموجود</button>
                @endif
            </div>
        </div>
    </div>

    {{-- Section Divider --}}
    <div class="w-full h-2 bg-gray-100 my-6 md:my-10 -mx-0"></div>

    {{-- Sticky Section Tabs --}}
    <div class="sticky-tabs w-full border-b border-gray-200 mb-8 overflow-x-auto hide-scrollbar">
        <div class="flex items-center gap-6 md:gap-8 px-4 md:px-0 min-w-max">
            <a href="#details-section" class="py-4 text-sm font-medium text-gray-500 hover:text-gray-800 transition-colors">مشخصات</a>
            @if($relatedProducts->isNotEmpty())
            <a href="#related-section" class="py-4 text-sm font-medium text-gray-500 hover:text-gray-800 transition-colors">محصولات مشابه</a>
            @endif
        </div>
    </div>

    {{-- Description Section --}}
    <div id="details-section" class="scroll-mt-24 mb-10 px-4 md:px-0">
        <div class="flex items-center gap-3 mb-5">
            <div class="w-1 h-6 bg-brandBlue rounded-full"></div>
            <h2 class="font-bold text-gray-800 text-lg">توضیحات محصول</h2>
        </div>
        <div class="bg-white border border-gray-100 rounded-2xl p-5 md:p-8 shadow-sm">
            <div class="product-description text-sm text-gray-700 leading-loose prose max-w-none">
                {!! nl2br(e($product->description ?? 'توضیحاتی برای این محصول ثبت نشده است.')) !!}
            </div>
        </div>
        <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="flex items-center gap-3 bg-gray-50 border border-gray-100 rounded-xl px-4 py-3">
                <i class="fa-solid fa-shop text-brandBlue"></i>
                <div>
                    <p class="text-[10px] text-gray-500 font-bold">مبدأ ارسال</p>
                    <p class="text-xs text-gray-800 font-bold">تهران، سعادت‌آباد</p>
                </div>
            </div>
            <div class="flex items-center gap-3 bg-gray-50 border border-gray-100 rounded-xl px-4 py-3">
                <i class="fa-solid fa-shield-halved text-brandBlue"></i>
                <div>
                    <p class="text-[10px] text-gray-500 font-bold">فروشنده</p>
                    <p class="text-xs text-gray-800 font-bold">فروشگاه گیم‌پک</p>
                </div>
            </div>
            <div class="flex items-center gap-3 bg-gray-50 border border-gray-100 rounded-xl px-4 py-3">
                <i class="fa-solid fa-headset text-brandBlue"></i>
                <div>
                    <p class="text-[10px] text-gray-500 font-bold">پشتیبانی</p>
                    <p class="text-xs text-gray-800 font-bold">پس از خرید</p>
                </div>
            </div>
        </div>
        @if($product->attributes && count($product->attributes) > 0)
        <div class="mt-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-1 h-6 bg-brandBlue rounded-full"></div>
                <h3 class="font-bold text-gray-800 text-base">مشخصات فنی</h3>
            </div>
            <div class="bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm divide-y divide-gray-50">
                @foreach($product->attributes as $key => $value)
                <div class="flex items-center px-5 py-3.5 {{ $loop->even ? 'bg-gray-50/50' : 'bg-white' }}">
                    <span class="w-2/5 text-xs md:text-sm text-gray-500 font-medium">{{ $key }}</span>
                    <span class="w-3/5 text-xs md:text-sm font-bold text-gray-800">{{ is_array($value) ? implode('، ', $value) : $value }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- Section Divider --}}
    <div class="w-full h-px bg-gray-100 my-8"></div>

    {{-- Related Products --}}
    @if($relatedProducts->isNotEmpty())
    <div id="related-section" class="scroll-mt-24 px-4 md:px-0">
        <h2 class="font-black text-lg text-gray-800 mb-4">محصولات مشابه</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
            @foreach($relatedProducts as $related)
            @include('partials.product-card', ['product' => $related])
            @endforeach
        </div>
    </div>
    @endif
</div>

{{-- Mobile Fixed Bottom Action Bar -- pushed to the 'fixed-bars' stack so it
     renders as a direct sibling of <main> in the layout, not nested inside
     this page's own content wrapper (see layouts/app.blade.php). --}}
@push('fixed-bars')
<div class="lg:hidden fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 z-40 shadow-[0_-4px_15px_rgba(0,0,0,0.05)] mb-[70px]">
    @if($product->isInStock() && isset($product->stock) && $product->stock <= 3)
    <div class="bg-red-50 text-flashRed text-[10px] font-bold py-1.5 px-4 text-center border-b border-red-100">
        <i class="fa-solid fa-fire mr-1"></i> تنها {{ $product->stock }} عدد در انبار باقی مانده
    </div>
    @endif
    <div class="flex items-center justify-between p-4">
        @if($product->isInStock())
        <button onclick="addToCartPage(PRODUCT_ID)" class="bg-brandBlue text-white text-sm font-bold py-3 px-6 rounded-xl shadow-lg shadow-blue-500/20">
            افزودن به سبد
        </button>
        @else
        <button disabled class="bg-gray-200 text-gray-400 text-sm font-bold py-3 px-6 rounded-xl cursor-not-allowed">ناموجود</button>
        @endif
        <div class="flex flex-col items-end">
            @if($product->sale_price)
            <span class="text-xs text-gray-400 line-through">{{ persian_number($product->price) }}</span>
            <span class="text-lg font-black text-brandDark"><span id="sticky-final-price">{{ persian_number($product->sale_price) }}</span> <span class="text-[10px] font-normal text-gray-500">تومان</span></span>
            @elseif($product->price)
            <span class="text-lg font-black text-brandDark"><span id="sticky-final-price">{{ persian_number($product->price) }}</span> <span class="text-[10px] font-normal text-gray-500">تومان</span></span>
            @endif
        </div>
    </div>
</div>
@endpush

@endsection

@push('scripts')
<script>
let qty = 1;
function changeQty(d) {
    qty = Math.max(1, Math.min(10, qty + d));
    document.getElementById('qty-display').textContent = qty;
}
function addToCartPage(productId) {
    addToCart(productId, qty, window.selectedProductOptions || null);
}
function switchImage(src, num) {
    document.getElementById('main-image').src = src;
    if (document.getElementById('img-counter')) document.getElementById('img-counter').textContent = num;
    document.querySelectorAll('.thumb-btn').forEach((el, i) => {
        el.classList.toggle('border-brandBlue', i+1 === num);
        el.classList.toggle('border-gray-200', i+1 !== num);
    });
}
</script>
@endpush
