@php
    // Admin-editable via پنل ادمین > منوها (location: "منوی دسته‌بندی‌ها").
    // The desktop mega-menu, the mobile fullscreen modal and the homepage
    // categories section all read MenuItem::categoryTree(), so one edit in
    // the panel updates all three — and its Category fallback means the menu
    // is never empty just because the taxonomy has not been entered yet.
    $categoryMenuTabs = \App\Models\MenuItem::categoryTree();

    // The small row of extra nav links next to the mega-menu — admin-editable
    // via پنل ادمین > منوها (location: "لینک‌های ناوبری هدر"). Deliberately NOT
    // location=header — that already holds unrelated pre-existing rows.
    $headerNavLinks = \App\Models\MenuItem::forLocation('header_nav_links')
        ->active()
        ->orderBy('sort_order')
        ->get();
@endphp
@if($headerPromo = setting('header.header_promo_text', ''))
<div class="w-full bg-brandBlue text-white text-xs py-1.5 text-center sticky top-0 z-50">{{ $headerPromo }}</div>
@endif
<header class="sticky top-0 z-40 w-full bg-white border-b border-gray-200 shadow-sm">
    <!-- Top bar (Desktop Only) -->
    <div class="hidden md:flex bg-brandDark text-white text-xs py-2 px-12 justify-between items-center">
        <div class="flex items-center gap-4">
            <span><i class="fa-solid fa-phone text-brandBlue ml-1"></i> {{ setting('header.topbar_support_text', 'پشتیبانی') }}: {{ setting('header.topbar_support_phone', '021-91004050') }}</span>
            <span><i class="fa-solid fa-shield-halved text-brandBlue ml-1"></i> {{ setting('header.topbar_trust_text', 'تضمین اصالت و سلامت کالا') }}</span>
        </div>
        {{-- «پیگیری سفارش» and «قوانین و مقررات» were removed from the top bar
             to keep it uncluttered. Their routes are untouched: order tracking
             now lives in the account area (profile / سفارش‌های من and the
             footer), and the terms page in the footer and the categories menu.
             --}}
    </div>

    <!-- Main Header -->
    <div class="max-w-[1400px] mx-auto px-4 py-3 md:py-4 flex flex-col md:flex-row items-center justify-between gap-4">
        <!-- Top section: Logo + Search -->
        <div class="flex items-center justify-between w-full md:w-auto gap-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <img src="{{ asset('images/logos/logo-horizontal.png') }}" alt="GamePek" class="h-10 md:h-12 w-auto" onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');">
                <span class="hidden text-2xl md:text-3xl font-black tracking-tight text-brandDark">گیم‌<span class="text-brandBlue">پک</span></span>
            </a>
            <!-- Desktop Search Bar -->
            <div class="relative hidden md:block w-[400px] lg:w-[500px]">
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-lg"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" id="search-input" name="q" placeholder="{{ setting('header.search_placeholder', 'جستجو در کنسول، بازی، لوازم جانبی...') }}"
                       class="w-full bg-brandGray text-sm py-3.5 pr-12 pl-4 rounded-xl border border-transparent focus:border-brandBlue focus:bg-white focus:shadow-md outline-none transition-all">
                <div id="search-results" class="absolute top-full right-0 w-full bg-white rounded-xl shadow-lg border border-gray-100 mt-1 hidden z-50"></div>
            </div>
        </div>

        <!-- Desktop Left Actions -->
        <div class="hidden md:flex items-center justify-end gap-4">
            {{-- Informational, not an action: there is no city picker behind
                 it (delivery is Tehran-only, see StoreAddressRequest). It was
                 a <button> with no handler -- clickable-looking and dead. --}}
            <div class="flex items-center gap-2 text-sm text-gray-600 py-2 px-3 rounded-xl">
                <i class="fa-solid fa-location-dot text-brandBlue text-lg"></i>
                <div class="text-right">
                    <p class="text-xs text-gray-400">{{ setting('header.location_label', 'ارسال به') }}</p>
                    <p class="font-bold text-xs">{{ setting('header.location_default_text', 'تهران') }}</p>
                </div>
            </div>
            <div class="h-8 w-px bg-gray-200"></div>

            @auth
                <a href="{{ route('profile.index') }}" class="flex items-center gap-2 text-sm font-semibold border border-gray-200 px-5 py-2.5 rounded-xl hover:bg-brandGray transition-all">
                    <i class="fa-regular fa-user text-lg"></i>
                    <span>{{ auth()->user()->full_name ?? 'پروفایل من' }}</span>
                </a>
            @else
                <a href="{{ route('auth.login') }}" class="flex items-center gap-2 text-sm font-semibold border border-gray-200 px-5 py-2.5 rounded-xl hover:bg-brandGray transition-all">
                    <i class="fa-regular fa-user text-lg"></i>
                    <span>{{ setting('header.login_button_text', 'ورود | ثبت‌نام') }}</span>
                </a>
            @endauth

            <!-- Hover Dropdown Mini Cart -->
            <div id="mini-cart-wrapper" class="relative group z-50">
                <a href="{{ route('cart.index') }}" class="relative p-3 bg-brandBlue text-white hover:bg-blue-600 rounded-xl transition-all shadow-md shadow-blue-500/10 flex items-center justify-center cursor-pointer">
                    <i class="fa-solid fa-cart-shopping text-lg"></i>
                    <span data-cart-count class="absolute -top-2 -left-2 bg-yellow-400 text-brandDark text-xs font-bold px-1.5 py-0.5 rounded-full min-w-5 text-center shadow-sm">0</span>
                </a>
                <!-- Dropdown Content -->
                <div class="absolute left-0 top-full mt-3 w-[360px] bg-white rounded-2xl shadow-xl border border-gray-100 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 flex flex-col overflow-hidden">
                    <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                        <span class="text-sm font-bold text-gray-800" id="mini-cart-count-label">در حال بارگذاری...</span>
                        <a href="{{ route('cart.index') }}" class="text-xs text-brandBlue font-bold flex items-center gap-1 hover:text-blue-700">مشاهده سبد خرید <i class="fa-solid fa-chevron-left text-[10px]"></i></a>
                    </div>
                    <div class="max-h-[320px] overflow-y-auto p-4 flex flex-col gap-4 hide-scrollbar" id="mini-cart-items">
                        <div class="text-center text-xs text-gray-400 py-4">سبد خرید شما خالی است</div>
                    </div>
                    <div class="p-4 bg-white border-t border-gray-100 flex justify-between items-center shadow-[0_-4px_15px_rgba(0,0,0,0.03)]">
                        <div class="flex flex-col">
                            <span class="text-[10px] text-gray-500 mb-0.5">مبلغ قابل پرداخت</span>
                            <span class="text-sm font-bold text-brandDark" id="mini-cart-total">-</span>
                        </div>
                        <a href="{{ route('cart.index') }}" class="bg-flashRed text-white text-xs font-bold py-2.5 px-5 rounded-xl hover:bg-red-600 transition-colors shadow-lg shadow-red-500/20">ثبت سفارش</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Search Bar (Full width below logo) -->
        <div class="relative w-full md:hidden">
            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400"><i class="fa-solid fa-magnifying-glass"></i></span>
            <input type="text" id="mobile-search-input" name="q" placeholder="{{ setting('header.search_placeholder', 'جستجو در گیم‌پک...') }}" class="w-full bg-brandGray text-sm py-3 pr-11 pl-4 rounded-xl border border-transparent outline-none focus:bg-white focus:border-brandBlue">
        </div>
    </div>

    <!-- Desktop Navigation (Hidden on mobile) -->
    <nav class="hidden md:block border-t border-gray-100 bg-white">
        <div class="max-w-[1400px] mx-auto px-4 flex items-center justify-between text-sm">
            <div class="flex items-center gap-6 py-3">
                <!-- Mega Menu (admin-editable: پنل ادمین > منوها > منوی دسته‌بندی‌ها) -->
                {{-- Opens on hover AND on click. Hover alone left the button
                     dead for keyboard and touch users; the JS below toggles
                     `data-open` on this wrapper, which the CSS honours the
                     same way it honours :hover. --}}
                <div class="relative group z-50" id="mega-menu" data-mega-menu>
                    <button type="button" id="mega-menu-button" aria-haspopup="true" aria-expanded="false" aria-controls="mega-menu-panel"
                            class="flex items-center gap-2 font-bold text-gray-800 group-hover:text-brandBlue py-1 transition-colors">
                        <i class="fa-solid fa-bars"></i>
                        <span>{{ setting('header.category_menu_title', 'دسته‌بندی کالاها') }}</span>
                        <i class="fa-solid fa-chevron-down text-[10px] text-gray-400 transition-transform"></i>
                    </button>
                    <!-- Dropdown Container -->
                    <div id="mega-menu-panel" class="mega-menu-panel absolute right-0 top-full mt-2 w-[900px] bg-white rounded-b-2xl shadow-xl border border-gray-100 opacity-0 invisible transition-all duration-300 flex h-[450px] overflow-hidden">
                        @if($categoryMenuTabs->isEmpty())
                        <div class="flex-1 flex flex-col items-center justify-center text-center px-8 text-gray-400">
                            <i class="fa-solid fa-folder-open text-4xl mb-3"></i>
                            <p class="text-sm text-gray-600 font-bold mb-1">هنوز دسته‌بندی‌ای ثبت نشده است.</p>
                            <p class="text-xs mb-4">دسته‌بندی‌ها از پنل ادمین (دسته‌بندی‌ها / منوها) اضافه می‌شوند.</p>
                            <a href="{{ route('products.index') }}" class="text-brandBlue text-sm font-bold">مشاهده همه موارد قابل اجاره</a>
                        </div>
                        @else
                        <!-- Right Sidebar (Main Categories) -->
                        <div class="w-64 bg-gray-50 border-l border-gray-100 flex flex-col py-2 z-10">
                            @foreach($categoryMenuTabs as $tab)
                            <div class="desk-cat-tab {{ $loop->first ? 'bg-white text-brandBlue border-brandBlue' : 'text-gray-700 hover:bg-gray-100 border-transparent' }} px-4 py-3.5 font-bold text-sm cursor-pointer flex items-center gap-3 transition-colors border-r-2"
                                 onmouseenter="switchDeskCat('desk-cat-{{ $tab->id }}', this)"
                                 onclick="switchDeskCat('desk-cat-{{ $tab->id }}', this)">
                                <i class="{{ $tab->icon ?: 'fa-solid fa-tag' }} text-lg w-6 text-center"></i> {{ $tab->title }}
                            </div>
                            @endforeach
                        </div>
                        <!-- Left Content Area -->
                        <div class="flex-1 p-6 bg-white overflow-y-auto">
                            @foreach($categoryMenuTabs as $tab)
                            <div id="desk-cat-{{ $tab->id }}" class="desk-cat-content {{ $loop->first ? 'block' : 'hidden' }}">
                                <a href="{{ $tab->resolved_url }}" class="flex items-center gap-2 text-brandBlue font-bold text-sm mb-6 hover:text-blue-700 transition-colors">مشاهده همه {{ $tab->title }} <i class="fa-solid fa-chevron-left text-[10px]"></i></a>
                                <div class="grid grid-cols-2 gap-8">
                                    @foreach($tab->children as $group)
                                    <div>
                                        <h4 class="font-bold text-gray-800 mb-4 text-[14px] border-r-2 border-brandBlue pr-2 leading-none">{{ $group->title }}</h4>
                                        <ul class="space-y-3 text-[13px] text-gray-600 pr-2">
                                            @foreach($group->children as $link)
                                            <li><a href="{{ $link->resolved_url }}" class="hover:text-brandBlue transition-colors">{{ $link->title }}</a></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>

                @foreach($headerNavLinks as $navLink)
                <a href="{{ $navLink->resolved_url }}" @if($navLink->opens_in_new_tab) target="_blank" rel="noopener noreferrer" @endif
                   class="{{ $navLink->icon === 'highlight' ? 'text-red-600 hover:text-red-700 font-bold' : 'text-gray-700 hover:text-brandBlue font-medium' }} transition-colors">{{ $navLink->title }}</a>
                @endforeach
            </div>
        </div>
    </nav>
</header>

<!-- Mobile Categories Full Screen Modal -->
<div id="mobile-category-modal" class="fixed inset-0 bg-white z-[60] hidden flex-col md:hidden transform translate-x-full transition-transform duration-300">
    <div class="px-4 py-3 border-b border-gray-200 bg-white">
        <div class="flex items-center gap-3">
            <div class="relative flex-1">
                <i class="fa-solid fa-magnifying-glass absolute right-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input type="text" placeholder="جستجو در دسته‌بندی‌ها..." class="w-full bg-gray-100 py-2.5 pr-9 pl-4 rounded-xl text-sm outline-none focus:bg-white focus:border focus:border-brandBlue transition-colors">
            </div>
            <button onclick="closeMobileCategories()" class="w-10 h-10 flex items-center justify-center text-gray-600 shrink-0"><i class="fa-solid fa-arrow-left text-lg"></i></button>
        </div>
    </div>
    @if($categoryMenuTabs->isEmpty())
    {{-- Never open onto a blank panel: say why it is empty and offer the
         catalog, which always exists. --}}
    <div class="flex-1 flex flex-col items-center justify-center text-center px-8 text-gray-400">
        <i class="fa-solid fa-folder-open text-4xl mb-3"></i>
        <p class="text-sm text-gray-600 font-bold mb-1">هنوز دسته‌بندی‌ای ثبت نشده است.</p>
        <a href="{{ route('products.index') }}" class="mt-3 bg-brandBlue text-white text-sm font-bold px-5 py-2.5 rounded-xl">مشاهده همه موارد قابل اجاره</a>
    </div>
    @else
    <div class="flex flex-1 overflow-hidden">
        <!-- Right Sidebar -->
        <div class="w-[28%] bg-gray-50 flex flex-col overflow-y-auto border-l border-gray-100 hide-scrollbar pb-24">
            @foreach($categoryMenuTabs as $tab)
            <button class="mob-cat-tab {{ $loop->first ? 'bg-white border-brandBlue text-brandBlue' : 'text-gray-500 border-transparent' }} border-r-2 flex flex-col items-center justify-center py-4 px-1 gap-2 transition-colors"
                    onclick="switchMobCat('mob-cat-{{ $tab->id }}', this)">
                <i class="{{ $tab->icon ?: 'fa-solid fa-tag' }} text-2xl"></i>
                <span class="text-[10px] font-bold text-center leading-tight">{{ $tab->title }}</span>
            </button>
            @endforeach
        </div>
        <!-- Left Content Area -->
        <div class="flex-1 overflow-y-auto bg-white p-4 pb-24">
            @foreach($categoryMenuTabs as $tab)
            <div id="mob-cat-{{ $tab->id }}" class="mob-cat-content {{ $loop->first ? 'block' : 'hidden' }}">
                <a href="{{ $tab->resolved_url }}" class="flex items-center gap-2 text-xs font-bold text-brandBlue mb-4 pb-2 border-b border-gray-100">مشاهده همه {{ $tab->title }} <i class="fa-solid fa-chevron-left text-[10px]"></i></a>
                @foreach($tab->children as $group)
                <div class="mb-2">
                    <button onclick="toggleMobAcc('mob-acc-{{ $group->id }}', this)" class="flex items-center justify-between w-full py-3 text-[13px] font-bold text-gray-800 {{ $loop->first ? '' : 'border-t border-gray-50' }}">
                        <span>{{ $group->title }}</span>
                        <i class="fa-solid fa-chevron-down text-gray-400 transition-transform"></i>
                    </button>
                    <div id="mob-acc-{{ $group->id }}" class="hidden flex-col gap-3 py-2 pr-2 text-xs text-gray-500">
                        @foreach($group->children as $link)
                        <a href="{{ $link->resolved_url }}" class="hover:text-brandBlue block py-1">{{ $link->title }}</a>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>

<style>
    /* The panel is visible when the wrapper is hovered OR marked open by the
       click handler. Both routes use the same two properties, so there is one
       visual state, not two competing ones. */
    #mega-menu:hover .mega-menu-panel,
    #mega-menu[data-open="true"] .mega-menu-panel { opacity: 1; visibility: visible; }
    #mega-menu[data-open="true"] #mega-menu-button .fa-chevron-down { transform: rotate(180deg); }

    @keyframes deskCatFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
    .desk-cat-fade-in { animation: deskCatFadeIn 0.25s ease-out; }
</style>
<script>
    // Mega menu open/close. Click toggles, Escape closes, a click outside
    // closes, and following a link closes it before the page navigates.
    (function () {
        const menu = document.getElementById('mega-menu');
        if (!menu) return;
        const button = document.getElementById('mega-menu-button');

        function setOpen(open) {
            menu.dataset.open = open ? 'true' : 'false';
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        button.addEventListener('click', e => {
            e.stopPropagation();
            setOpen(menu.dataset.open !== 'true');
        });

        document.addEventListener('click', e => {
            if (!menu.contains(e.target)) setOpen(false);
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') setOpen(false);
        });

        setOpen(false);
    })();

    // Desktop Category Mega Menu Tab Switcher
    function switchDeskCat(catId, element) {
        const target = document.getElementById(catId);
        const scrollArea = target.parentElement;

        document.querySelectorAll('.desk-cat-content').forEach(el => {
            el.classList.add('hidden');
            el.classList.remove('block');
            el.classList.remove('desk-cat-fade-in');
        });
        target.classList.remove('hidden');
        target.classList.add('block');
        // Restart the fade-in animation on every switch (re-adding the same
        // class name is a no-op without this reflow trick).
        void target.offsetWidth;
        target.classList.add('desk-cat-fade-in');

        if (scrollArea) {
            scrollArea.scrollTo({ top: 0, behavior: 'smooth' });
        }

        document.querySelectorAll('.desk-cat-tab').forEach(el => {
            el.classList.remove('bg-white', 'text-brandBlue', 'border-brandBlue');
            el.classList.add('text-gray-700', 'border-transparent', 'hover:bg-gray-100');
        });
        element.classList.remove('text-gray-700', 'border-transparent', 'hover:bg-gray-100');
        element.classList.add('bg-white', 'text-brandBlue', 'border-brandBlue');
    }

    // Mobile Categories
    function openMobileCategories() {
        const modal = document.getElementById('mobile-category-modal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => { modal.classList.remove('translate-x-full'); }, 10);
        document.body.style.overflow = 'hidden';
    }
    function closeMobileCategories() {
        const modal = document.getElementById('mobile-category-modal');
        modal.classList.add('translate-x-full');
        setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 300);
        document.body.style.overflow = '';
    }
    // Escape closes the fullscreen categories modal as well.
    document.addEventListener('keydown', e => {
        const modal = document.getElementById('mobile-category-modal');
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) closeMobileCategories();
    });

    function switchMobCat(catId, element) {
        const target = document.getElementById(catId);
        const scrollArea = target.parentElement;

        document.querySelectorAll('.mob-cat-content').forEach(el => {
            el.classList.add('hidden');
            el.classList.remove('block');
            el.classList.remove('desk-cat-fade-in');
        });
        target.classList.remove('hidden');
        target.classList.add('block');
        void target.offsetWidth;
        target.classList.add('desk-cat-fade-in');

        if (scrollArea) {
            scrollArea.scrollTo({ top: 0, behavior: 'smooth' });
        }

        document.querySelectorAll('.mob-cat-tab').forEach(el => {
            el.classList.remove('bg-white', 'text-brandBlue', 'border-brandBlue');
            el.classList.add('text-gray-500', 'border-transparent');
        });
        element.classList.remove('text-gray-500', 'border-transparent');
        element.classList.add('bg-white', 'text-brandBlue', 'border-brandBlue');
    }
    function toggleMobAcc(id, element) {
        const acc = document.getElementById(id);
        const icon = element.querySelector('i');
        if (acc.classList.contains('hidden')) {
            acc.classList.remove('hidden'); acc.classList.add('flex');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            acc.classList.add('hidden'); acc.classList.remove('flex');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    // Live search
    const searchInput = document.getElementById('search-input');
    const mobileSearchInput = document.getElementById('mobile-search-input');
    const searchResults = document.getElementById('search-results');
    function goToSearch(q) {
        q = (q || '').trim();
        if (q.length >= 1) window.location.href = `{{ route('products.search') }}?q=${encodeURIComponent(q)}`;
    }
    [searchInput, mobileSearchInput].forEach(input => {
        if (!input) return;
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); goToSearch(this.value); }
        });
    });
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimeout);
            const q = this.value.trim();
            if (q.length < 2) { searchResults.classList.add('hidden'); return; }
            searchTimeout = setTimeout(() => {
                fetch(`/api/products/search?q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (!data.results || !data.results.length) { searchResults.classList.add('hidden'); return; }
                        searchResults.innerHTML = data.results.map(item => {
                            if (item.type === 'product') {
                                return `<a href="${item.url}" class="flex items-center gap-3 p-3 hover:bg-gray-50 border-b border-gray-50 last:border-0">
                                    <div class="w-10 h-10 rounded-lg bg-gray-50 flex items-center justify-center shrink-0 overflow-hidden border border-gray-100">
                                        ${item.image_url ? `<img src="${item.image_url}" class="w-full h-full object-contain p-1">` : `<i class="fa-solid fa-gamepad text-gray-400"></i>`}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-bold text-gray-800 line-clamp-1">${item.title}</p>
                                        <p class="text-xs text-brandBlue font-bold">${item.price_fa || ''}</p>
                                    </div>
                                    <i class="fa-solid fa-chevron-left text-[10px] text-gray-300"></i>
                                </a>`;
                            }
                            return `<a href="${item.url}" class="flex items-center gap-3 p-3 hover:bg-gray-50 border-b border-gray-50 last:border-0">
                                <div class="w-10 h-10 rounded-lg bg-brandLightBlue text-brandBlue flex items-center justify-center shrink-0 overflow-hidden">
                                    ${item.image_url ? `<img src="${item.image_url}" class="w-full h-full object-cover">` : `<i class="${item.icon || 'fa-solid fa-border-all'}"></i>`}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-bold text-gray-800 line-clamp-1">${item.title}</p>
                                    <p class="text-xs text-gray-500">${item.subtitle || 'دسته‌بندی محصولات'}</p>
                                </div>
                                <i class="fa-solid fa-chevron-left text-[10px] text-gray-300"></i>
                            </a>`;
                        }).join('');
                        searchResults.classList.remove('hidden');
                    }).catch(() => {});
            }, 300);
        });
        document.addEventListener('click', e => { if (searchResults && !searchInput.contains(e.target)) searchResults.classList.add('hidden'); });
    }

    // Mini cart loader
    function loadMiniCart() {
        const miniCartItems = document.getElementById('mini-cart-items');
        const miniCartLabel = document.getElementById('mini-cart-count-label');
        const miniCartTotal = document.getElementById('mini-cart-total');
        if (!miniCartItems || !miniCartLabel || !miniCartTotal) return;
        fetch('{{ route('cart.mini') }}')
            .then(r => r.json())
            .then(d => {
                if (typeof updateCartBadge === 'function' && d.count !== undefined) updateCartBadge(d.count);
                if (!d.items || !d.items.length) {
                    miniCartLabel.textContent = 'سبد خرید خالی';
                    miniCartItems.innerHTML = '<div class="text-center text-xs text-gray-400 py-4">سبد خرید شما خالی است</div>';
                    miniCartTotal.textContent = '-';
                    return;
                }
                miniCartLabel.textContent = (d.count_formatted || d.items.length) + ' کالا';
                miniCartItems.innerHTML = d.items.map(item => `
                    <div class="flex gap-3 relative border-b border-gray-50 pb-4 last:border-0 last:pb-0">
                        <div class="w-20 h-20 bg-gray-50 rounded-xl flex items-center justify-center shrink-0 border border-gray-100 p-1">
                            <img src="${item.image || 'https://placehold.co/100x100/F5F5F5/111?text=PS'}" class="w-full h-full object-contain mix-blend-multiply">
                        </div>
                        <div class="flex flex-col justify-between flex-1 py-1">
                            <h4 class="text-xs font-bold text-gray-800 line-clamp-2 leading-relaxed">${item.title}</h4>
                            <div class="flex items-center justify-between mt-2">
                                <span class="text-[10px] text-gray-500 border border-gray-200 px-1.5 py-0.5 rounded text-center min-w-5">${item.quantity}</span>
                                <span class="text-xs font-bold text-brandDark">${item.price_formatted} <span class="text-[9px] font-normal text-gray-500">تومان</span></span>
                            </div>
                        </div>
                    </div>`).join('');
                miniCartTotal.textContent = d.total_formatted + ' تومان';
                if (typeof updateCartBadge === 'function' && d.count !== undefined) updateCartBadge(d.count);
            }).catch(() => {
                miniCartLabel.textContent = 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.';
            });
    }

    // Load mini cart when hovering
    const cartGroup = document.getElementById('mini-cart-wrapper');
    if (cartGroup) {
        cartGroup.addEventListener('mouseenter', loadMiniCart);
    }
</script>
