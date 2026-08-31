{{-- Admin topbar: mobile sidebar toggle, breadcrumb, page badge, admin identity. --}}

{{-- ── Topbar ── --}}
<header class="bg-white border-b border-gray-200 px-3 md:px-6 py-3.5 flex items-center justify-between shrink-0 z-20">
    <div class="flex items-center gap-4">
        {{-- Mobile sidebar toggle --}}
        <button onclick="toggleSidebar()" class="lg:hidden text-gray-500 hover:text-gray-800 p-1">
            <i class="fa-solid fa-bars text-lg"></i>
        </button>

        {{-- Breadcrumb --}}
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('admin.dashboard') }}" class="hover:text-brandBlue transition-colors">خانه</a>
            @hasSection('breadcrumb')
                <i class="fa-solid fa-chevron-left text-[10px]"></i>
                @yield('breadcrumb')
            @endif
        </div>
    </div>

    <div class="flex items-center gap-4">
        {{-- Page title badge --}}
        <span class="hidden md:inline-flex text-xs font-medium text-brandBlue bg-brandLightBlue px-3 py-1 rounded-full">
            @yield('page_badge', 'داشبورد')
        </span>

        {{-- Visit site --}}
        <a href="{{ route('home') }}" target="_blank"
           class="hidden md:flex items-center gap-1.5 text-xs text-gray-500 hover:text-brandBlue border border-gray-200 hover:border-brandBlue px-3 py-1.5 rounded-lg transition-all">
            <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
            مشاهده سایت
        </a>

        {{-- Admin name --}}
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-full bg-brandBlue flex items-center justify-center">
                <i class="fa-solid fa-user text-white text-xs"></i>
            </div>
            <div class="hidden md:block text-right">
                <p class="text-xs font-semibold text-gray-800 leading-tight">{{ auth()->user()->full_name ?? 'مدیر' }}</p>
                <p class="text-[10px] text-gray-400">
                    @if(auth()->user()->hasRole('super_admin')) سوپر ادمین
                    @elseif(auth()->user()->hasRole('admin')) مدیر
                    @elseif(auth()->user()->hasRole('product_manager')) مدیر محصولات
                    @elseif(auth()->user()->hasRole('order_manager')) مدیر سفارش‌ها
                    @elseif(auth()->user()->hasRole('content_manager')) مدیر محتوا
                    @else پشتیبانی @endif
                </p>
            </div>
        </div>
    </div>
</header>
