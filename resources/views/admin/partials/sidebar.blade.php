{{-- Admin sidebar. Extracted from the Store's 717-line layout monolith,
     which inlined sidebar, topbar, flash and ~280 lines of JS in one file. --}}
{{-- ══════════ SIDEBAR ══════════ --}}
<aside id="sidebar" class="fixed lg:relative top-0 right-0 w-64 bg-sidebar flex flex-col h-full overflow-y-auto transition-transform duration-300 z-50 translate-x-full lg:translate-x-0">

    {{-- Logo --}}
    <div class="flex items-center gap-3 px-5 py-5 border-b border-white/10">
        <img src="{{ asset('images/logos/logo-icon.png') }}" alt="GamePek" class="w-9 h-9 rounded-xl object-contain bg-white/5 shrink-0" onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');">
        <div class="hidden w-9 h-9 bg-brandBlue rounded-xl flex items-center justify-center shrink-0 text-white font-black text-sm">GP</div>
        <div>
            <div class="text-white font-bold text-sm leading-tight">گیم‌پک اجاره</div>
            <div class="text-blue-300 text-[10px]">پنل مدیریت</div>
        </div>
    </div>

    {{-- Nav --}}
    @php
        try {
            $pendingOrdersBadge = \Illuminate\Support\Facades\Cache::remember('admin_sidebar_pending_orders_badge', 60, function () {
                return \App\Models\Order::where(function ($query) {
                    $query->whereIn('status', ['pending_payment', 'processing'])
                        ->orWhere(function ($paidQuery) {
                            $paidQuery->where('payment_status', 'paid')
                                ->whereNotIn('status', ['delivered', 'cancelled', 'refunded', 'failed']);
                        });
                })->count();
            });
        } catch (\Throwable $e) {
            report($e);
            $pendingOrdersBadge = 0;
        }

        try {
            $unreadMessagesBadge = \Illuminate\Support\Facades\Cache::remember('admin_sidebar_unread_messages_badge', 60, function () {
                return \App\Models\Conversation::where('status', 'open')
                    ->where(function ($query) {
                        $query->whereNull('admin_read_at')
                            ->orWhereColumn('admin_read_at', '<', 'last_message_at');
                    })->count();
            });
        } catch (\Throwable $e) {
            report($e);
            $unreadMessagesBadge = 0;
        }
    @endphp
    <nav class="flex-1 px-3 py-4 flex flex-col gap-1">

        {{-- Active: Dashboard --}}
        <a href="{{ route('admin.dashboard') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
            <i class="fa-solid fa-gauge-high w-5 text-center text-blue-300"></i>
            <span>داشبورد</span>
        </a>

        {{-- Divider --}}
        <div class="my-2 border-t border-white/10"></div>
        <p class="text-[10px] text-white/30 px-3 mb-1 uppercase tracking-widest">مدیریت</p>

        {{-- Products--}}
        @can('view_products')
        <a href="{{ route('admin.products.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
            <i class="fa-solid fa-box w-5 text-center text-blue-300"></i>
            <span>محصولات</span>
        </a>
        @endcan

        {{-- Categories--}}
        @can('view_categories')
        <a href="{{ route('admin.categories.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
            <i class="fa-solid fa-layer-group w-5 text-center text-blue-300"></i>
            <span>دسته‌بندی‌ها</span>
        </a>
        @endcan

        {{-- Orders--}}
        @can('view_orders')
        <a href="{{ route('admin.orders.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">
            <i class="fa-solid fa-bag-shopping w-5 text-center text-blue-300"></i>
            <span class="flex-1">سفارش‌ها</span>
            <span class="bg-red-500 text-white text-[10px] font-bold min-w-[18px] h-[18px] rounded-full flex items-center justify-center px-1 shrink-0 leading-none">{{ $pendingOrdersBadge > 99 ? '+۹۹' : persian_number($pendingOrdersBadge) }}</span>
        </a>
        @endcan

        {{-- Messages --}}
        @can('view_messages')
        <a href="{{ route('admin.messages.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.messages.*') ? 'active' : '' }}">
            <i class="fa-solid fa-message w-5 text-center text-blue-300"></i>
            <span class="flex-1">پیام‌ها</span>
            @if($unreadMessagesBadge > 0)
            <span class="bg-red-500 text-white text-[10px] font-bold min-w-[18px] h-[18px] rounded-full flex items-center justify-center px-1 shrink-0 leading-none">{{ $unreadMessagesBadge > 99 ? '+۹۹' : persian_number($unreadMessagesBadge) }}</span>
            @endif
        </a>
        @endcan

        {{-- Payments--}}
        @can('view_payments')
        <a href="{{ route('admin.payments.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.payments.*') ? 'active' : '' }}">
            <i class="fa-solid fa-credit-card w-5 text-center text-blue-300"></i>
            <span>پرداخت‌ها</span>
        </a>
        @endcan

        {{-- Wallet--}}
        @can('view_payments')
        <a href="{{ route('admin.wallet.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.wallet.*') ? 'active' : '' }}">
            <i class="fa-solid fa-wallet w-5 text-center text-blue-300"></i>
            <span>کیف پول</span>
        </a>
        @endcan

        {{-- Users--}}
        @can('view_users')
        <a href="{{ route('admin.users.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
            <i class="fa-solid fa-users w-5 text-center text-blue-300"></i>
            <span>کاربران</span>
        </a>
        @endcan

        {{-- Coupons --}}
        @can('view_coupons')
        <a href="{{ route('admin.coupons.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.coupons.*') ? 'active' : '' }}">
            <i class="fa-solid fa-ticket w-5 text-center text-blue-300"></i>
            <span>کدهای تخفیف</span>
        </a>
        @endcan

        {{-- Shipping Methods --}}
        @can('view_shipping_methods')
        <a href="{{ route('admin.shipping-methods.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.shipping-methods.*') ? 'active' : '' }}">
            <i class="fa-solid fa-truck-fast w-5 text-center text-blue-300"></i>
            <span>روش‌های ارسال</span>
        </a>
        @endcan

        <div class="my-2 border-t border-white/10"></div>
        <p class="text-[10px] text-white/30 px-3 mb-1 uppercase tracking-widest">محتوا</p>

        {{-- Banners--}}
        @can('view_banners')
        <a href="{{ route('admin.banners.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.banners.*') ? 'active' : '' }}">
            <i class="fa-solid fa-image w-5 text-center text-blue-300"></i>
            <span>بنرها</span>
        </a>
        @endcan

        {{-- Home Sections--}}
        @can('view_home_sections')
        <a href="{{ route('admin.home-sections.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.home-sections.*') ? 'active' : '' }}">
            <i class="fa-solid fa-house-chimney w-5 text-center text-blue-300"></i>
            <span>بخش‌های صفحه اصلی</span>
        </a>
        @endcan

        {{-- Quick Categories--}}
        @can('manage_quick_categories')
        <a href="{{ route('admin.quick-categories.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.quick-categories.*') ? 'active' : '' }}">
            <i class="fa-solid fa-th-large w-5 text-center text-blue-300"></i>
            <span>دسته‌بندی‌های سریع</span>
        </a>
        @endcan

        {{-- Trust Badges--}}
        @can('manage_trust_badges')
        <a href="{{ route('admin.trust-badges.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.trust-badges.*') ? 'active' : '' }}">
            <i class="fa-solid fa-shield-halved w-5 text-center text-blue-300"></i>
            <span>نشان‌های اعتماد</span>
        </a>
        @endcan

        {{-- Menus--}}
        @can('manage_menus')
        <a href="{{ route('admin.menus.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.menus.*') ? 'active' : '' }}">
            <i class="fa-solid fa-bars-staggered w-5 text-center text-blue-300"></i>
            <span>منوها</span>
        </a>
        @endcan

        <div class="my-2 border-t border-white/10"></div>
        <p class="text-[10px] text-white/30 px-3 mb-1 uppercase tracking-widest">اجاره</p>
        @can('view_rental_applications')
        <a href="{{ route('admin.rental-applications.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.rental-applications.*') ? 'active' : '' }}">
            <i class="fa-solid fa-file-signature w-5 text-center text-blue-300"></i>
            <span>درخواست‌های اجاره</span>
        </a>
        @endcan
        @can('view_verifications')
        <a href="{{ route('admin.verifications.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.verifications.*') ? 'active' : '' }}">
            <i class="fa-solid fa-id-card w-5 text-center text-blue-300"></i>
            <span>احراز هویت</span>
        </a>
        @endcan
        @can('view_audit_events')
        <a href="{{ route('admin.audit-events.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.audit-events.*') ? 'active' : '' }}">
            <i class="fa-solid fa-shield-halved w-5 text-center text-blue-300"></i>
            <span>لاگ ممیزی</span>
        </a>
        @endcan

        @can('view_reports')
        <a href="{{ route('admin.reports.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.reports.*') ? 'active' : '' }}">
            <i class="fa-solid fa-chart-bar w-5 text-center text-blue-300"></i>
            <span>گزارش‌ها</span>
        </a>
        @endcan
        @can('view_activity_logs')
        <a href="{{ route('admin.activity-logs.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.activity-logs.*') ? 'active' : '' }}">
            <i class="fa-solid fa-history w-5 text-center text-blue-300"></i>
            <span>لاگ فعالیت‌ها</span>
        </a>
        @endcan

        <div class="my-2 border-t border-white/10"></div>
        <p class="text-[10px] text-white/30 px-3 mb-1 uppercase tracking-widest">سیستم</p>

        {{-- Settings--}}
        @if(auth()->user()->canAny(['manage_settings', 'manage_theme_settings', 'manage_seo_settings', 'manage_notification_settings']))
        <a href="{{ route('admin.settings.index') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white text-sm {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">
            <i class="fa-solid fa-gear w-5 text-center text-blue-300"></i>
            <span>تنظیمات</span>
        </a>
        @endif
    </nav>

    {{-- Bottom user info --}}
    <div class="px-4 py-4 border-t border-white/10">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-brandBlue/20 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-user text-brandBlue text-xs"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-white text-xs font-medium truncate">{{ auth()->user()->full_name ?? 'مدیر' }}</p>
                <p class="text-white/40 text-[10px] truncate">{{ auth()->user()->email }}</p>
            </div>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="text-white/40 hover:text-red-400 transition-colors" title="خروج">
                    <i class="fa-solid fa-right-from-bracket text-sm"></i>
                </button>
            </form>
        </div>
    </div>
</aside>
