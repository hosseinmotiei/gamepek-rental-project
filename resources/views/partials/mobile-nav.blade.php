<nav class="md:hidden fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 z-50 pb-safe shadow-[0_-4px_10px_rgba(0,0,0,0.05)]">
    <div class="flex justify-around items-center p-2 pt-3">
        <!-- Home -->
        <a href="{{ route('home') }}" class="flex flex-col items-center gap-1 {{ request()->routeIs('home') ? 'text-brandBlue' : 'text-gray-500 hover:text-brandBlue' }} transition-colors w-1/5">
            <i class="fa-solid fa-house text-xl mb-0.5"></i>
            <span class="text-[10px] font-bold">خانه</span>
        </a>

        <!-- Categories - triggers full-screen modal -->
        <button onclick="openMobileCategories()" class="flex flex-col items-center gap-1 {{ request()->routeIs('products.*') ? 'text-brandBlue' : 'text-gray-500 hover:text-brandBlue' }} transition-colors w-1/5">
            <i class="fa-solid fa-border-all text-xl mb-0.5"></i>
            <span class="text-[10px] font-bold">دسته‌بندی</span>
        </button>

        <!-- Cart -->
        <a href="{{ route('cart.index') }}" class="flex flex-col items-center gap-1 {{ request()->routeIs('cart.*') ? 'text-brandBlue' : 'text-gray-500 hover:text-brandBlue' }} transition-colors relative w-1/5">
            <div class="relative">
                <i class="fa-solid fa-cart-shopping text-xl mb-0.5"></i>
                <span data-cart-count class="absolute -top-1.5 -right-2.5 bg-yellow-400 text-brandDark text-[9px] font-bold px-1.5 py-0.5 rounded-full min-w-4 text-center">0</span>
            </div>
            <span class="text-[10px] font-bold">سبد خرید</span>
        </a>

        <!-- Search (mobile only) -->
        <button onclick="document.querySelector('.md\\:hidden input[type=text]')?.focus()" class="flex flex-col items-center gap-1 text-gray-500 hover:text-brandBlue transition-colors w-1/5">
            <i class="fa-solid fa-magnifying-glass text-xl mb-0.5"></i>
            <span class="text-[10px] font-bold">جستجو</span>
        </button>

        <!-- Profile / Login -->
        @auth
            <a href="{{ route('profile.index') }}" class="flex flex-col items-center gap-1 {{ request()->routeIs('profile.*') ? 'text-brandBlue' : 'text-gray-500 hover:text-brandBlue' }} transition-colors w-1/5">
                <i class="fa-regular fa-user text-xl mb-0.5"></i>
                <span class="text-[10px] font-bold">پروفایل</span>
            </a>
        @else
            <a href="{{ route('auth.login') }}" class="flex flex-col items-center gap-1 text-gray-500 hover:text-brandBlue transition-colors w-1/5">
                <i class="fa-regular fa-user text-xl mb-0.5"></i>
                <span class="text-[10px] font-bold">ورود</span>
            </a>
        @endauth
    </div>
</nav>
