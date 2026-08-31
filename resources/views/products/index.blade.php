@extends('layouts.app')

@section('title', 'گیم‌پک اجاره | موارد قابل اجاره')

@push('styles')
<style>
    body { background-color:#FFFFFF !important; }
    body.modal-open { overflow: hidden; }
    .accordion-content { transition: max-height 0.3s ease-out; overflow: hidden; max-height: 500px; }
    .accordion-content.collapsed { max-height: 0; }
    .accordion-icon { transition: transform 0.3s ease; }
    .accordion-icon.rotated { transform: rotate(180deg); }
    .filter-btn-active { color: #0066FF !important; font-weight: bold !important; }
    .filter-check-active { background-color: #0066FF; border-color: #0066FF; }
</style>
@endpush

@section('content')
@php
    // Server-side filter/sort state (query string is the single source of truth).
    $currentCategory = (string) request('category', '');
    $currentSort     = (string) request('sort', 'newest');

    // Facet keys come from CatalogService::availableFacets() via the
    // controller -- never hardcoded here, so rental facets need no view edit.
    $facetKeys       = array_keys($facets ?? []);
    $selectedFacets  = $selectedFacets ?? [];
    $hasActiveFilter = $currentCategory !== '' || count(array_filter($selectedFacets)) > 0;

    // Reset link: clears the category and every declared facet at once.
    $clearFilters = array_merge(['category' => null], array_fill_keys($facetKeys, null));

    $sortLabels = [
        'newest'       => 'جدیدترین',
        'most_viewed'  => 'پربازدیدترین',
        'best_selling' => 'پرفروش‌ترین',
        'cheapest'     => 'ارزان‌ترین',
        'expensive'    => 'گران‌ترین',
    ];

    $buildUrl = function (array $overrides) {
        $params = array_merge(request()->query(), $overrides);
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        unset($params['page']);
        return route('products.index', $params);
    };

    $activeCategoryName = $currentCategory !== ''
        ? optional($categories->firstWhere('slug', $currentCategory))->name_fa
        : null;
    $pageTitle = $activeCategoryName ?? 'همه محصولات';
@endphp

{{-- Mobile Title / Action Bar --}}
<div class="md:hidden sticky top-0 z-30 w-full bg-white border-b border-gray-100 flex flex-col transition-all">
    <div class="flex items-center justify-between px-4 py-3">
        <div class="flex items-center gap-3">
            <a href="{{ url()->previous() ?: route('home') }}" class="text-gray-600"><i class="fa-solid fa-arrow-right text-lg"></i></a>
            <h1 class="font-bold text-gray-800 text-sm">{{ $pageTitle }}</h1>
        </div>
        <div class="flex items-center gap-4">
            <a href="{{ route('products.search') }}" class="text-gray-600"><i class="fa-solid fa-magnifying-glass text-lg"></i></a>
        </div>
    </div>

    <div class="flex items-center border-t border-gray-100 py-2.5 px-4 overflow-x-auto hide-scrollbar gap-3 bg-white">
        <button onclick="openMobileFilter()" class="flex items-center gap-1 text-xs font-medium text-gray-700 shrink-0 px-2">
            <i class="fa-solid fa-sliders text-gray-500"></i> فیلتر
        </button>
        <div class="w-px h-4 bg-gray-200 shrink-0"></div>
        <button onclick="openMobileSort()" class="flex items-center gap-1 text-xs font-medium text-gray-700 shrink-0 px-2">
            <i class="fa-solid fa-arrow-down-short-wide text-gray-500"></i>
            <span>{{ $sortLabels[$currentSort] ?? $sortLabels['newest'] }}</span>
        </button>
    </div>
</div>

<main class="max-w-[1400px] mx-auto px-0 md:px-4 py-4 md:py-6 flex flex-col md:flex-row gap-6 bg-white">

    {{-- Desktop Sidebar Filters --}}
    <aside class="hidden md:flex w-[280px] shrink-0 flex-col gap-4 sticky top-24 self-start max-h-[calc(100vh-120px)] overflow-y-auto hide-scrollbar border border-gray-200 rounded-2xl p-5 bg-white shadow-sm">

        <div class="flex items-center justify-between mb-2">
            <h2 class="font-bold text-gray-800 text-lg">فیلترها</h2>
            @if($hasActiveFilter)
            <a href="{{ $buildUrl($clearFilters) }}" class="text-xs font-bold text-cyan-500 hover:text-cyan-600 transition-colors">حذف فیلترها</a>
            @endif
        </div>

        {{-- Categories --}}
        <div class="py-2 border-b border-gray-100">
            <button onclick="toggleAccordion('filter-category')" class="flex items-center justify-between w-full py-2 text-gray-800 hover:text-brandBlue transition-colors group">
                <span class="text-sm font-bold">دسته‌بندی</span>
                <i class="fa-solid fa-chevron-down text-xs text-gray-400 accordion-icon group-hover:text-brandBlue" id="icon-filter-category"></i>
            </button>
            <div id="filter-category" class="accordion-content collapsed">
                <div class="flex flex-col gap-2 pt-2 pb-2">
                    <a href="{{ $buildUrl(['category' => null]) }}" class="text-sm text-right transition-colors {{ $currentCategory === '' ? 'text-brandBlue font-bold' : 'text-gray-800 hover:text-brandBlue' }}">همه محصولات</a>
                    @foreach($categories as $cat)
                        @php $catActive = $currentCategory === $cat->slug; @endphp
                        <a href="{{ $buildUrl(['category' => $catActive ? null : $cat->slug]) }}" class="text-sm text-right transition-colors {{ $catActive ? 'text-brandBlue font-bold' : 'text-gray-600 hover:text-brandBlue' }}">{{ $cat->name_fa }}</a>
                    @endforeach
                </div>
            </div>
        </div>

        @include('partials.facet-filter', [
            'facets' => $facets ?? [],
            'selectedFacets' => $selectedFacets,
            'buildUrl' => $buildUrl,
            'idPrefix' => '',
        ])

        @if($sidebarBanners->isNotEmpty())
        {{-- Sidebar Banners --}}
        <div class="flex flex-col gap-4 pt-2">
            @foreach($sidebarBanners as $banner)
                @continue(!$banner->image)
                @php $sbLink = $banner->safe_link; @endphp
                @if($sbLink)
                <a href="{{ $sbLink }}" @if($banner->opens_in_new_tab) target="_blank" rel="noopener noreferrer" @endif
                   class="block rounded-xl overflow-hidden" aria-label="{{ $banner->title ?: 'بنر تبلیغاتی' }}">
                    <img src="{{ media_url($banner->image) }}" alt="{{ $banner->title ?: 'بنر تبلیغاتی' }}" loading="lazy" decoding="async" class="w-full h-auto object-cover">
                </a>
                @else
                <div class="rounded-xl overflow-hidden" role="img" aria-label="{{ $banner->title ?: 'بنر تبلیغاتی' }}">
                    <img src="{{ media_url($banner->image) }}" alt="{{ $banner->title ?: 'بنر تبلیغاتی' }}" loading="lazy" decoding="async" class="w-full h-auto object-cover">
                </div>
                @endif
            @endforeach
        </div>
        @endif
    </aside>

    {{-- Content Area --}}
    <div class="flex-1 flex flex-col min-w-0">

        {{-- Breadcrumbs --}}
        <div class="hidden md:flex items-center gap-2 text-xs text-gray-500 mb-4">
            <a href="{{ route('home') }}" class="hover:text-brandBlue">فروشگاه اینترنتی گیم‌پک</a>
            <i class="fa-solid fa-angle-left text-[10px]"></i>
            <a href="{{ route('products.index') }}" class="hover:text-brandBlue">کالای دیجیتال</a>
            <i class="fa-solid fa-angle-left text-[10px]"></i>
            <span class="text-gray-800">{{ $pageTitle }}</span>
        </div>

        {{-- Desktop Sort Bar --}}
        <div class="hidden md:flex items-center gap-4 border-b border-gray-200 mb-6 pb-0">
            <div class="flex items-center gap-2 text-gray-800 font-bold text-sm shrink-0 mb-3">
                <i class="fa-solid fa-arrow-down-short-wide"></i> مرتب‌سازی:
            </div>
            <div class="flex items-center gap-6 text-sm text-gray-500 font-medium overflow-x-auto hide-scrollbar">
                @foreach($sortLabels as $key => $label)
                    <a href="{{ $buildUrl(['sort' => $key === 'newest' ? null : $key]) }}" class="pb-3 hover:text-gray-800 whitespace-nowrap {{ $currentSort === $key ? 'tab-active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
            <div class="mr-auto text-xs text-gray-400 mb-3 shrink-0">{{ persian_number($products->total()) }} کالا</div>
        </div>

        <div class="md:hidden text-xs text-gray-400 px-4 mb-2">{{ persian_number($products->total()) }} کالا</div>

        @if($products->isEmpty())
        {{-- Empty state --}}
        <div class="flex flex-col items-center justify-center py-20 text-center px-4 bg-white border-t border-b border-gray-100">
            <i class="fa-solid fa-search text-5xl text-gray-200 mb-4"></i>
            <p class="text-gray-500 text-sm mb-2">محصولی با این فیلترها پیدا نشد.</p>
            @if($hasActiveFilter)
            <a href="{{ $buildUrl($clearFilters) }}" class="mt-2 text-brandBlue text-sm font-bold hover:underline">پاک‌کردن فیلترها</a>
            @endif
        </div>
        @else
        {{-- Products Grid --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-0 md:gap-4 divide-y divide-gray-100 sm:divide-y-0 border-t border-b border-gray-100 sm:border-none">
            @foreach($products as $product)
                @php
                    $price = $product->sale_price ?? $product->price;
                    $image = product_image_url($product);
                @endphp
                <div class="product-card flex flex-col p-4 sm:border border-gray-100 sm:rounded-xl group relative hover:shadow-lg transition-shadow bg-white h-full">

                    @if($product->stock_status === 'coming_soon')
                        <span class="absolute top-4 right-4 text-brandBlue text-[10px] font-bold bg-white/80 px-1.5 py-0.5 rounded shadow-sm z-10">جدید</span>
                    @elseif($product->is_flash_sale || $product->discount_percent > 0)
                        <span class="absolute top-4 right-4 text-red-500 text-[10px] font-bold bg-white/80 px-1.5 py-0.5 rounded shadow-sm z-10">فروش ویژه</span>
                    @endif

                    <a href="{{ route('products.show', $product->slug) }}" class="w-full flex justify-center py-4 relative bg-transparent shrink-0">
                        <img src="{{ $image }}" alt="{{ $product->title_fa }}" class="w-32 h-32 md:w-48 md:h-48 object-contain mix-blend-multiply rounded-xl group-hover:scale-105 transition-transform duration-300" loading="lazy" decoding="async" width="192" height="192">
                    </a>

                    <div class="flex flex-col flex-1 mt-2 justify-between">
                        <div>
                            <a href="{{ route('products.show', $product->slug) }}">
                                <h3 class="text-xs md:text-sm font-bold text-gray-800 line-clamp-2 h-10 mb-2 leading-relaxed">{{ $product->title_fa }}</h3>
                            </a>

                            <div class="flex items-center justify-between mb-3">
                            </div>

                            @if($product->stock_quantity > 0 && $product->stock_quantity <= 2)
                                <span class="text-[10px] text-flashRed font-bold mb-2 block">تنها {{ persian_number($product->stock_quantity) }} عدد در انبار باقی مانده</span>
                            @endif
                        </div>

                        <div class="mt-4 pt-2 pl-12 sm:pl-0 border-t border-gray-50 md:border-none md:pt-0">
                            @if($product->stock_status === 'in_stock')
                                @if($product->sale_price && $product->discount_percent > 0)
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="bg-flashRed text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">{{ persian_number($product->discount_percent) }}٪</span>
                                        <span class="text-[10px] text-gray-400 line-through">{{ persian_number($product->price) }}</span>
                                    </div>
                                @endif
                                <div class="flex justify-end items-center gap-1 mb-2">
                                    <span class="text-base md:text-lg font-bold text-gray-900">{{ persian_number($price) }}</span>
                                    <span class="text-[10px] text-gray-600">تومان</span>
                                </div>
                                <button onclick="addToCart({{ $product->id }})" class="w-full text-brandBlue font-bold text-xs border border-brandBlue hover:bg-blue-50 py-2 rounded-lg transition-colors items-center justify-center gap-2 opacity-0 group-hover:opacity-100 sm:flex hidden">
                                    <i class="fa-solid fa-cart-plus"></i> افزودن به سبد
                                </button>
                            @elseif($product->stock_status === 'preorder')
                                <div class="flex justify-end items-center gap-1 mb-2"><span class="text-xs font-bold text-brandBlue">پیش‌خرید</span></div>
                            @elseif($product->stock_status === 'coming_soon')
                                <div class="flex justify-end items-center gap-1 mb-2"><span class="text-xs font-bold text-brandBlue">بزودی</span></div>
                            @else
                                <div class="flex justify-end items-center gap-1 mb-2"><span class="text-xs font-bold text-gray-400">ناموجود</span></div>
                            @endif
                        </div>
                    </div>

                    @if($product->stock_status === 'in_stock')
                        <button onclick="addToCart({{ $product->id }})" class="absolute bottom-4 left-4 w-8 h-8 rounded-full border border-gray-200 text-brandBlue flex sm:hidden items-center justify-center bg-gray-50 hover:bg-brandBlue hover:text-white hover:border-brandBlue transition-colors">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6 px-4 md:px-0">{{ $products->links() }}</div>
        @endif
    </div>
</main>

{{-- Mobile Sort Bottom Sheet --}}
<div id="mobile-sort-modal" class="fixed inset-0 bg-black/50 z-[60] hidden flex-col justify-end transition-opacity duration-300 opacity-0 pb-safe">
    <div class="bg-white w-full rounded-t-2xl flex flex-col transform translate-y-full transition-transform duration-300 max-h-[80vh]" id="mobile-sort-inner">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 shrink-0">
            <h3 class="font-bold text-gray-800 text-sm">مرتب‌سازی بر اساس</h3>
            <button onclick="closeMobileSort()" class="text-gray-400 hover:text-gray-800"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <div class="flex flex-col p-2 gap-1 pb-6 overflow-y-auto hide-scrollbar">
            @foreach($sortLabels as $key => $label)
                @php $sortActive = $currentSort === $key; @endphp
                <a href="{{ $buildUrl(['sort' => $key === 'newest' ? null : $key]) }}" class="flex items-center justify-between px-4 py-3 rounded-xl {{ $sortActive ? 'text-brandBlue font-bold bg-blue-50' : 'text-gray-700 font-medium' }}">
                    <span class="text-sm">{{ $label }}</span><i class="fa-solid fa-check {{ $sortActive ? 'text-brandBlue' : 'text-transparent' }}"></i>
                </a>
            @endforeach
        </div>
    </div>
</div>

{{-- Mobile Filter Full Screen --}}
<div id="mobile-filter-modal" class="fixed inset-0 bg-white z-[60] hidden flex-col transition-transform duration-300 translate-y-full pb-safe h-[100dvh]">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 shadow-sm shrink-0">
        <h3 class="font-bold text-gray-800 text-sm">فیلترها</h3>
        @if($hasActiveFilter)
        <a href="{{ $buildUrl($clearFilters) }}" class="text-xs font-bold text-cyan-500 mr-auto ml-4">حذف همه</a>
        @endif
        <button onclick="closeMobileFilter()" class="text-gray-500 hover:text-gray-800"><i class="fa-solid fa-xmark text-lg"></i></button>
    </div>

    <div class="flex-1 overflow-y-auto px-4 py-2 hide-scrollbar">

        <div class="py-3 border-b border-gray-100">
            <button onclick="toggleAccordion('m-filter-category')" class="flex items-center justify-between w-full py-2 text-gray-800 transition-colors group">
                <span class="text-sm font-bold">دسته‌بندی</span>
                <i class="fa-solid fa-chevron-down text-xs text-gray-400 accordion-icon transition-transform" id="icon-m-filter-category"></i>
            </button>
            <div id="m-filter-category" class="accordion-content collapsed">
                <div class="flex flex-col gap-3 pt-2 pb-2">
                    <a href="{{ $buildUrl(['category' => null]) }}" class="text-sm text-right {{ $currentCategory === '' ? 'text-brandBlue font-bold' : 'text-gray-600 hover:text-brandBlue' }}">همه محصولات</a>
                    @foreach($categories as $cat)
                        @php $mCatActive = $currentCategory === $cat->slug; @endphp
                        <a href="{{ $buildUrl(['category' => $mCatActive ? null : $cat->slug]) }}" class="text-sm text-right {{ $mCatActive ? 'text-brandBlue font-bold' : 'text-gray-600 hover:text-brandBlue' }}">{{ $cat->name_fa }}</a>
                    @endforeach
                </div>
            </div>
        </div>

        @include('partials.facet-filter', [
            'facets' => $facets ?? [],
            'selectedFacets' => $selectedFacets,
            'buildUrl' => $buildUrl,
            'idPrefix' => 'm-',
        ])
    </div>
</div>
@endsection

@push('scripts')
<script>
// Pure UI display toggles (accordions / mobile sheets) -- unrelated to data
// filtering, which is now handled entirely server-side via query-string links.
function toggleAccordion(id) {
    const content = document.getElementById(id);
    const icon    = document.getElementById(`icon-${id}`);
    if (!content) return;
    content.classList.toggle('collapsed');
    if (icon) icon.classList.toggle('rotated');
}

function openMobileSort() {
    const modal = document.getElementById('mobile-sort-modal');
    const inner = document.getElementById('mobile-sort-inner');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => { modal.classList.remove('opacity-0'); inner.classList.remove('translate-y-full'); }, 10);
    document.body.classList.add('modal-open');
}
function closeMobileSort() {
    const modal = document.getElementById('mobile-sort-modal');
    const inner = document.getElementById('mobile-sort-inner');
    modal.classList.add('opacity-0');
    inner.classList.add('translate-y-full');
    setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.classList.remove('modal-open'); }, 300);
}
function openMobileFilter() {
    const modal = document.getElementById('mobile-filter-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modal.classList.remove('translate-y-full'), 10);
    document.body.classList.add('modal-open');
}
function closeMobileFilter() {
    const modal = document.getElementById('mobile-filter-modal');
    modal.classList.add('translate-y-full');
    setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.classList.remove('modal-open'); }, 300);
}
</script>
@endpush
