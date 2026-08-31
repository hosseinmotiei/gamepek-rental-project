{{--
    The single catalog card.

    The Store had three divergent card markups (this partial, an inline copy
    in products/index, and an inline flash-sale copy in home) that had drifted
    apart. They are consolidated here, so a change to the card is made once.

    Params:
      $product  — required
      $variant  — 'default' (white card) | 'flash' (on the red flash panel)

    Wishlist and notify-me buttons are gone: both features belong to the
    Store and were not cloned.
--}}
@php
    $variant = $variant ?? 'default';
@endphp
<div class="bg-white rounded-2xl p-3 md:p-4 shadow-sm border border-gray-100 flex flex-col group relative min-w-0 overflow-hidden">

    @if($product->stock_status === 'coming_soon')
        <span class="absolute top-3 left-3 bg-blue-100 text-brandBlue text-[10px] font-bold px-2 py-1 rounded z-10 animate-pulse">بزودی</span>
    @elseif($product->is_flash_sale && $product->discount_percent > 0)
        <span class="absolute top-3 left-3 bg-flashRed text-white text-[10px] font-bold px-2 py-1 rounded z-10">{{ persian_number($product->discount_percent) }}٪</span>
    @endif

    <a href="{{ route('products.show', $product->slug) }}">
        <img src="{{ product_image_url($product) }}"
             alt="{{ $product->title_fa }}"
             loading="lazy"
             decoding="async"
             width="240"
             height="240"
             class="w-full h-32 md:h-48 object-contain mb-4 rounded-xl group-hover:scale-105 transition-transform duration-300">
        <h4 class="font-bold text-xs md:text-sm text-gray-800 mb-2 line-clamp-2 h-8 md:h-10">{{ $product->title_fa }}</h4>
    </a>

    <div class="mt-auto pt-4 border-t border-gray-50">
        @if($product->stock_status === 'in_stock')
            <div class="text-left mb-3">
                @if($product->sale_price)
                    <span class="text-xs text-gray-400 line-through block text-right">{{ persian_number($product->price) }} تومان</span>
                @endif
                <span class="text-base md:text-lg font-bold text-brandDark whitespace-nowrap">{{ persian_number($product->sale_price ?? $product->price) }}</span>
                <span class="text-[10px] md:text-xs text-gray-500"> تومان</span>
            </div>
            <button onclick="addToCart({{ $product->id }})"
                    class="w-full font-bold py-2 rounded-xl transition-colors text-xs md:text-sm {{ $variant === 'flash' ? 'bg-flashRed text-white hover:bg-red-600' : 'bg-brandGray text-brandBlue group-hover:bg-brandBlue group-hover:text-white' }}">
                <i class="fa-solid fa-cart-plus ml-1"></i> {{ setting('product_display.add_to_cart_text', 'افزودن به سبد') }}
            </button>
        @elseif($product->stock_status === 'out_of_stock')
            <div class="text-center py-2">
                <span class="text-sm font-bold text-gray-400">{{ setting('product_display.out_of_stock_text', 'ناموجود') }}</span>
            </div>
        @else
            <div class="text-center py-2">
                <span class="text-xs md:text-sm font-bold text-brandBlue">بزودی</span>
            </div>
        @endif
    </div>
</div>
