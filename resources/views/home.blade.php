@extends('layouts.app')

@section('title', setting('seo.home_meta_title', 'گیم‌پک اجاره | اجاره کنسول بازی و لوازم جانبی'))
@section('meta_description', setting('seo.home_meta_description', 'اجاره آنلاین کنسول بازی و لوازم جانبی از گیم‌پک.'))

@push('meta')
@if(setting_image_url('seo.open_graph_image'))
<meta property="og:image" content="{{ setting_image_url('seo.open_graph_image') }}">
@endif
@endpush


@section('content')
{{-- Hero: the entry point. Headline, one line of context, and the rental
     search bar -- the primary action of the whole site. Full-bleed so it
     reads as the page opener; the rest of the page keeps the 1400px rail. --}}
<section class="bg-gradient-to-l from-brandDark via-brandDark to-blue-900 text-white">
    <div class="max-w-[1400px] mx-auto px-4 pt-10 pb-8 md:pt-16 md:pb-14">
        <div class="max-w-3xl">
            <span class="inline-block bg-white/10 border border-white/15 text-[11px] md:text-xs font-bold px-3 py-1.5 rounded-full mb-4">گیم‌پک اجاره</span>
            <h1 class="text-2xl sm:text-4xl md:text-5xl font-black leading-tight mb-3">
                {{ setting('home.hero_title', 'کنسول بازی را اجاره کن، نه بخر') }}
            </h1>
            <p class="text-sm md:text-base text-blue-100/90 leading-relaxed mb-7 max-w-xl">
                {{ setting('home.hero_subtitle', 'تاریخ شروع و پایان را انتخاب کن و دستگاه‌های آزاد همان بازه را ببین.') }}
            </p>
        </div>

        {{-- The one component; the results page re-uses it. --}}
        @include('partials.rental-search-bar', ['variant' => 'hero'])

        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 mt-5 text-[11px] md:text-xs text-blue-100/80">
            <span><i class="fa-solid fa-shield-halved ml-1"></i> قرارداد رسمی و احراز هویت</span>
            <span><i class="fa-solid fa-clock-rotate-left ml-1"></i> اجاره روزانه تا چند هفته</span>
            <span><i class="fa-solid fa-headset ml-1"></i> پشتیبانی همه‌روزه</span>
        </div>
    </div>
</section>

<div class="max-w-[1400px] mx-auto px-4 py-6 md:py-8">

    {{-- محصولات پیشنهادی — real rentable devices, rendered with the one
         catalog card (partials/product-card). Each card already links to
         products.show, so there is no second navigation path to keep in
         sync. Hidden entirely when nothing is rentable yet rather than
         showing an empty rail. --}}
    @if(($homeSections['featured_products'] ?? true) && $homeData['rentable']->isNotEmpty())
    <section class="mb-12">
        <div class="flex items-center justify-between mb-4 md:mb-6">
            <h2 class="text-base md:text-xl font-black text-gray-800">محصولات پیشنهادی</h2>
            <a href="{{ route('products.index') }}" class="text-xs md:text-sm text-brandBlue font-bold flex items-center gap-1 hover:text-blue-700 transition-colors">
                مشاهده همه <i class="fa-solid fa-chevron-left text-[10px]"></i>
            </a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3 md:gap-4">
            @foreach($homeData['rentable'] as $product)
                @include('partials.product-card', ['product' => $product])
            @endforeach
        </div>
    </section>
    @endif

    {{-- دسته‌بندی‌ها — the same admin-managed tree the header menu reads, so
         one edit in پنل ادمین > منوها updates the header, the mobile menu and
         this section together. --}}
    @if($categoryMenu->isNotEmpty())
    <section class="mb-12">
        <div class="flex items-center justify-between mb-4 md:mb-6">
            <h2 class="text-base md:text-xl font-black text-gray-800">دسته‌بندی‌ها</h2>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
            @foreach($categoryMenu as $tab)
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <a href="{{ $tab->resolved_url }}" class="flex items-center gap-3 mb-3 group">
                    <span class="w-10 h-10 rounded-xl bg-brandLightBlue text-brandBlue flex items-center justify-center shrink-0">
                        <i class="{{ $tab->icon ?: 'fa-solid fa-tag' }}"></i>
                    </span>
                    <span class="font-bold text-sm text-gray-800 group-hover:text-brandBlue transition-colors">{{ $tab->title }}</span>
                </a>
                @if($tab->children->isNotEmpty())
                <ul class="space-y-2 pr-13 text-xs text-gray-600">
                    @foreach($tab->children as $child)
                    <li>
                        <a href="{{ $child->resolved_url }}" class="hover:text-brandBlue transition-colors flex items-center gap-1.5">
                            <i class="fa-solid fa-chevron-left text-[8px] text-gray-300"></i> {{ $child->title }}
                        </a>
                    </li>
                    @endforeach
                </ul>
                @endif
            </div>
            @endforeach
        </div>
    </section>
    @endif
    {{-- Promotional banner carousel (admin-managed) --}}
    <section class="mb-8 md:mb-10 relative">
        <div id="hero-carousel" class="relative overflow-hidden rounded-2xl md:rounded-3xl aspect-[2.8/1] min-h-[160px] w-full shadow-lg">
            @forelse($banners as $index => $banner)
            @php $bannerLink = $banner->safe_link; @endphp
            @if($bannerLink)
            <a href="{{ $bannerLink }}" @if($banner->opens_in_new_tab) target="_blank" rel="noopener noreferrer" @endif
               class="carousel-item {{ $index === 0 ? 'active' : '' }} block"
               aria-label="{{ $banner->title ?: 'بنر تبلیغاتی' }}"
               style="background-image: {{ $banner->image ? 'url(' . media_url($banner->image) . ')' : 'none' }}; background-size: cover; background-position: center; {{ !$banner->image && $banner->bg_gradient ? 'background: ' . $banner->bg_gradient : '' }} position: absolute; top:0; left:0; width:100%; height:100%; z-index:0; opacity:0; transition: opacity 0.5s ease-in-out; pointer-events: none;">
            @else
            <div class="carousel-item {{ $index === 0 ? 'active' : '' }}"
                 role="img"
                 aria-label="{{ $banner->title ?: 'بنر تبلیغاتی' }}"
                 style="background-image: {{ $banner->image ? 'url(' . media_url($banner->image) . ')' : 'none' }}; background-size: cover; background-position: center; {{ !$banner->image && $banner->bg_gradient ? 'background: ' . $banner->bg_gradient : '' }} position: absolute; top:0; left:0; width:100%; height:100%; z-index:0; opacity:0; transition: opacity 0.5s ease-in-out; pointer-events: none;">
            @endif
            @if($bannerLink)
            </a>
            @else
            </div>
            @endif
            @empty
            {{-- Fallback static banners if no DB banners --}}
            <div class="carousel-item active" style="background: linear-gradient(to left, rgba(0,20,60,0.95), rgba(0,40,120,0.3)); position:absolute; top:0; left:0; width:100%; height:100%; z-index:10; pointer-events:auto;">
                <div class="absolute right-6 md:right-16 top-1/2 -translate-y-1/2 text-white max-w-lg z-10">
                    <span class="bg-brandBlue text-[10px] md:text-sm font-bold px-4 py-1.5 rounded-full inline-block mb-4">گیم‌پک اجاره</span>
                    <h1 class="text-2xl sm:text-4xl md:text-5xl font-black leading-tight mb-4">اجاره کنسول بازی<br>و لوازم جانبی</h1>
                    <p class="hidden sm:block text-sm md:text-base text-blue-100 mb-6">این بنر پیش‌فرض است و تنها زمانی نمایش داده می‌شود که هیچ بنری در پنل ادمین ثبت نشده باشد.</p>
                    <a href="{{ route('products.index') }}" class="inline-block bg-white text-brandBlue font-bold py-3.5 px-8 rounded-xl transition-all shadow-lg">مشاهده موارد <i class="fa-solid fa-arrow-left text-xs mr-1"></i></a>
                </div>
            </div>
            @endforelse

            <button type="button" aria-label="اسلاید قبلی" onclick="prevSlide()" class="absolute right-2 md:right-4 top-1/2 -translate-y-1/2 w-8 h-8 md:w-12 md:h-12 bg-white/20 hover:bg-white/40 backdrop-blur-md rounded-full flex items-center justify-center text-white z-20 transition-all"><i class="fa-solid fa-chevron-right text-sm md:text-base"></i></button>
            <button type="button" aria-label="اسلاید بعدی" onclick="nextSlide()" class="absolute left-2 md:left-4 top-1/2 -translate-y-1/2 w-8 h-8 md:w-12 md:h-12 bg-white/20 hover:bg-white/40 backdrop-blur-md rounded-full flex items-center justify-center text-white z-20 transition-all"><i class="fa-solid fa-chevron-left text-sm md:text-base"></i></button>
            <div class="absolute bottom-3 md:bottom-6 left-1/2 -translate-x-1/2 flex gap-1.5 md:gap-2 z-20" id="carousel-indicators"></div>
        </div>
    </section>

    @if($homeSections['features_row'] ?? true)
    {{-- Features Row -- compact strip on mobile (was 3 tall stacked cards
         eating a full screen right under the hero), full cards from sm up. --}}
    <section class="mb-10">
        <div class="grid grid-cols-3 sm:grid-cols-3 gap-2 sm:gap-3 md:gap-6">
            @foreach([['fa-motorcycle','ارسال همان‌روز','ویژه شهر تهران'],['fa-certificate','ضمانت اصالت','کالای ۱۰۰٪ اورجینال'],['fa-headset','پشتیبانی همه‌روزه','پاسخگویی سریع']] as [$icon,$title,$sub])
            <div class="bg-white p-2 sm:p-3 md:p-4 rounded-xl md:rounded-2xl flex flex-col sm:flex-row items-center sm:items-start text-center sm:text-right gap-1.5 sm:gap-4 shadow-sm border border-gray-100">
                <div class="w-8 h-8 sm:w-10 sm:h-10 md:w-12 md:h-12 bg-blue-50 text-brandBlue rounded-full flex items-center justify-center text-sm sm:text-lg md:text-xl shrink-0"><i class="fa-solid {{ $icon }}"></i></div>
                <div><h4 class="font-bold text-[10px] sm:text-xs md:text-sm text-gray-800 leading-snug">{{ $title }}</h4><p class="hidden sm:block text-[10px] md:text-xs text-gray-500 mt-0.5">{{ $sub }}</p></div>
            </div>
            @endforeach
        </div>
    </section>
    @endif

    @if($secondaryBanners->isNotEmpty())
    {{-- Secondary Banners --}}
    <section class="mb-10 grid grid-cols-1 {{ $secondaryBanners->count() > 1 ? 'md:grid-cols-2' : '' }} gap-4">
        @foreach($secondaryBanners as $banner)
            @continue(!$banner->image)
            @php $secLink = $banner->safe_link; @endphp
            @if($secLink)
            <a href="{{ $secLink }}" @if($banner->opens_in_new_tab) target="_blank" rel="noopener noreferrer" @endif
               class="block rounded-2xl overflow-hidden" aria-label="{{ $banner->title ?: 'بنر تبلیغاتی' }}">
                <img src="{{ media_url($banner->image) }}" alt="{{ $banner->title ?: 'بنر تبلیغاتی' }}" loading="lazy" decoding="async" class="w-full h-auto object-cover">
            </a>
            @else
            <div class="rounded-2xl overflow-hidden" role="img" aria-label="{{ $banner->title ?: 'بنر تبلیغاتی' }}">
                <img src="{{ media_url($banner->image) }}" alt="{{ $banner->title ?: 'بنر تبلیغاتی' }}" loading="lazy" decoding="async" class="w-full h-auto object-cover">
            </div>
            @endif
        @endforeach
    </section>
    @endif

    @if($homeSections['quick_categories'] ?? true)
    {{-- Quick Categories --}}
    <section class="mb-12">
        <div class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-8 gap-3 md:gap-4 text-center">
            @foreach($quickCategories as $qc)
            @php
                $quickIcon = trim((string) $qc->icon);
                if (str_contains($qc->title, 'همه')) {
                    $quickIcon = 'fa-solid fa-border-all';
                } elseif ($quickIcon === '') {
                    $quickIcon = 'fa-solid fa-gamepad';
                } elseif (!str_contains($quickIcon, 'fa-regular') && !str_contains($quickIcon, 'fa-solid') && !str_contains($quickIcon, 'fa-brands')) {
                    $quickIcon = 'fa-solid ' . $quickIcon;
                }
            @endphp
            <a href="{{ $qc->link ?: '#' }}" @if($qc->opens_in_new_tab) target="_blank" rel="noopener noreferrer" @endif class="group flex flex-col items-center gap-2">
                @if($qc->image)
                <div class="w-14 h-14 md:w-20 md:h-20 rounded-xl md:rounded-2xl overflow-hidden shadow-sm border border-gray-100 bg-white">
                    <img src="{{ media_url($qc->image) }}" alt="{{ $qc->title }}" loading="lazy" decoding="async" width="80" height="80" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300">
                </div>
                @else
                <div class="w-14 h-14 md:w-20 md:h-20 {{ $qc->bg_class ?: 'bg-white' }} rounded-xl md:rounded-2xl flex items-center justify-center {{ $qc->color_class ?: 'text-brandBlue' }} group-hover:bg-brandBlue group-hover:text-white transition-all duration-300 shadow-sm border border-gray-100">
                    <i class="{{ $quickIcon }} text-2xl md:text-3xl"></i>
                </div>
                @endif
                <span class="text-[10px] md:text-xs font-semibold {{ $qc->highlight ? 'text-red-600' : 'text-gray-700' }} group-hover:text-brandBlue">{{ $qc->title }}</span>
                @if($qc->subtitle)
                <span class="text-[9px] text-gray-400 -mt-1 hidden md:block">{{ $qc->subtitle }}</span>
                @endif
            </a>
            @endforeach
        </div>
    </section>
    @endif

    {{-- Flash Sale --}}
    @if(($homeSections['flash_sale'] ?? true) && $homeData['flash_sale']->isNotEmpty())
    <section id="flash-sale" class="mb-12">
        <div class="bg-flashRed rounded-2xl md:rounded-3xl p-4 md:p-6 flex flex-col md:flex-row items-center gap-6 shadow-lg overflow-hidden relative">
            <div class="absolute -right-20 -top-20 w-64 h-64 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
            <div class="w-full md:w-48 lg:w-64 flex flex-col items-center justify-center text-white shrink-0 z-10 mb-4 md:mb-0">
                <h2 class="text-2xl md:text-3xl font-black mb-2 text-center">پیشنهاد<br>شگفت‌انگیز</h2>
                <i class="fa-solid fa-bolt text-4xl md:text-5xl text-yellow-300 mb-4"></i>
                <div class="flex gap-2 text-brandDark font-bold mb-4" dir="ltr">
                    <div class="bg-white rounded-lg w-10 h-10 flex items-center justify-center"><span id="fs-hours">--</span></div>
                    <span class="text-white text-xl font-black">:</span>
                    <div class="bg-white rounded-lg w-10 h-10 flex items-center justify-center"><span id="fs-mins">--</span></div>
                    <span class="text-white text-xl font-black">:</span>
                    <div class="bg-white rounded-lg w-10 h-10 flex items-center justify-center"><span id="fs-secs">--</span></div>
                </div>
                <a href="{{ route('products.index', ['flash_sale' => 1]) }}" class="text-xs md:text-sm border border-white/50 hover:bg-white/10 rounded-full px-4 py-1.5 transition-colors">مشاهده همه <i class="fa-solid fa-arrow-left text-xs mr-1"></i></a>
            </div>
            <div class="w-full overflow-x-auto flex gap-3 md:gap-4 pb-2 hide-scrollbar z-10">
                @foreach($homeData['flash_sale'] as $product)
                {{-- Same shared card as everywhere else, in its flash variant.
                     The Store had a third, separately-maintained card markup here. --}}
                <div class="min-w-[200px] md:min-w-[240px] max-w-[240px] shrink-0">
                    @include('partials.product-card', ['product' => $product, 'variant' => 'flash'])
                </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif




    {{-- Admin-added category product sections (پنل ادمین > بخش‌های صفحه اصلی > بخش محصولات جدید) --}}
    @foreach($customProductSections as $section)
    @php $sectionProducts = $section->resolveProducts(); @endphp
    @if($sectionProducts->isNotEmpty())
    @php $sectionTotalCount = $section->category_id ? \App\Models\Product::active()->where('category_id', $section->category_id)->count() : $sectionProducts->count(); @endphp
    <section class="mb-12">
        <div class="flex justify-between items-end mb-6">
            <div>
                <h3 class="text-xl md:text-2xl font-black text-gray-800">{{ $section->title }}</h3>
                @if($section->subtitle)
                <p class="text-xs md:text-sm text-gray-500 mt-1">{{ $section->subtitle }}</p>
                @endif
            </div>
            <a href="{{ $section->cta_link ?: route('products.index', ['category' => $section->category?->slug]) }}" class="shrink-0 flex items-center gap-2 text-sm md:text-base text-brandBlue font-bold border-2 border-brandBlue/30 hover:bg-brandLightBlue px-4 md:px-5 py-2 md:py-2.5 rounded-xl transition-colors">
                {{ $section->cta_text ?: 'مشاهده همه' }}{{ $sectionTotalCount > $sectionProducts->count() ? ' ('.persian_number($sectionTotalCount).' محصول)' : '' }} <i class="fa-solid fa-arrow-left text-xs md:text-sm"></i>
            </a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-6">
            @foreach($sectionProducts as $product)
            @include('partials.product-card', ['product' => $product])
            @endforeach
        </div>
    </section>
    @endif
    @endforeach

    {{-- Best Sellers --}}
    @if(($homeSections['best_sellers'] ?? true) && $homeData['best_sellers']->isNotEmpty())
    <section class="mb-12">
        <div class="flex justify-between items-end mb-6">
            <div>
                <h3 class="text-xl md:text-2xl font-black text-gray-800">پرطرفدارترین‌ها</h3>
                <p class="text-xs md:text-sm text-gray-500 mt-1">بیشترین اجاره در گیم‌پک</p>
            </div>
            <a href="{{ route('products.index', ['best_seller' => 1]) }}" class="text-xs md:text-sm text-brandBlue font-bold hover:underline hidden sm:block">مشاهده همه <i class="fa-solid fa-arrow-left text-xs md:text-sm"></i></a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-6">
            @foreach($homeData['best_sellers'] as $product)
            @include('partials.product-card', ['product' => $product])
            @endforeach
        </div>
    </section>
    @endif


</div>
@endsection

@push('scripts')
<script>
    // Carousel
    let currentSlide = 0;
    const items = document.querySelectorAll('.carousel-item');
    const indicatorsContainer = document.getElementById('carousel-indicators');

    function buildIndicators() {
        items.forEach((_, i) => {
            const btn = document.createElement('button');
            btn.className = `h-1 md:h-1.5 rounded-full transition-all ${i === 0 ? 'w-6 md:w-8 bg-brandBlue' : 'w-2 md:w-3 bg-white/50'}`;
            btn.onclick = () => goToSlide(i);
            indicatorsContainer?.appendChild(btn);
        });
    }

    function goToSlide(n) {
        items[currentSlide].style.opacity = '0';
        items[currentSlide].style.zIndex = '0';
        items[currentSlide].style.pointerEvents = 'none';
        currentSlide = (n + items.length) % items.length;
        items[currentSlide].style.opacity = '1';
        items[currentSlide].style.zIndex = '10';
        items[currentSlide].style.pointerEvents = 'auto';
        document.querySelectorAll('#carousel-indicators button').forEach((btn, i) => {
            btn.className = `h-1 md:h-1.5 rounded-full transition-all ${i === currentSlide ? 'w-6 md:w-8 bg-brandBlue' : 'w-2 md:w-3 bg-white/50'}`;
        });
    }
    function nextSlide() { goToSlide(currentSlide + 1); }
    function prevSlide() { goToSlide(currentSlide - 1); }

    if (items.length > 0) {
        items[0].style.opacity = '1';
        items[0].style.zIndex = '10';
        items[0].style.pointerEvents = 'auto';
        buildIndicators();
        setInterval(nextSlide, 5000);
    }

    // Flash sale countdown (24 hrs from midnight)
    function updateFlashTimer() {
        const now = new Date();
        const endOfDay = new Date(); endOfDay.setHours(23,59,59,0);
        const diff = Math.max(0, Math.floor((endOfDay - now) / 1000));
        const h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
        const pad = n => String(n).padStart(2, '0');
        ['fs-hours','fs-mins','fs-secs'].forEach((id,i) => {
            const el = document.getElementById(id);
            if (el) el.textContent = pad([h,m,s][i]);
        });
    }
    setInterval(updateFlashTimer, 1000); updateFlashTimer();

</script>
@endpush
