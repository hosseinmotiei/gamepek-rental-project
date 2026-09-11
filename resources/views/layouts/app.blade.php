<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
        if (!location.hash) { window.scrollTo(0, 0); }
    </script>
    <title>@yield('title', setting('seo.home_meta_title', 'گیم‌پک اجاره'))</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logos/logo-icon.png') }}">
    <meta name="description" content="@yield('meta_description', setting('seo.home_meta_description', 'اجاره آنلاین کنسول بازی و لوازم جانبی از گیم‌پک'))">
    <meta name="robots" content="{{ setting('seo.robots_index', '1') == '1' ? 'index' : 'noindex' }},{{ setting('seo.robots_follow', '1') == '1' ? 'follow' : 'nofollow' }}">
    @stack('meta')

    @include('partials.design-tokens')

    @stack('styles')
</head>
<body class="bg-gray-50 pb-20 md:pb-0">

    <!-- Toast Notification Container -->
    <div id="toast-container" class="fixed top-6 md:top-24 left-6 z-[60] flex flex-col gap-3 pointer-events-none"></div>

    @include('partials.header')

    <main>
        @yield('content')
    </main>

    {{-- Page-specific fixed/sticky bars pushed here render as a direct
         sibling of <main> (same structural level as the working bottom nav
         below), never nested inside any content wrapper -- keeps them
         immune to whatever ancestor inside a page's own markup might
         otherwise interfere with position:fixed. --}}
    @stack('fixed-bars')

    @include('partials.footer')
    @include('partials.mobile-nav')

    <script>
        // Global CSRF token for AJAX
        const CSRF_TOKEN = '{{ csrf_token() }}';
        const BASE_URL = '{{ url('/') }}';

        // Toast notification system
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            const icons = { success: '<i class="fa-solid fa-circle-check text-green-400"></i>', error: '<i class="fa-solid fa-circle-exclamation text-red-400"></i>', info: '<i class="fa-solid fa-circle-info text-blue-400"></i>' };
            toast.className = `flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-white text-xs md:text-sm transform transition-all duration-300 -translate-y-full opacity-0 bg-gray-800`;
            toast.innerHTML = `${icons[type] || icons.info} <span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => toast.classList.remove('-translate-y-full', 'opacity-0'), 10);
            setTimeout(() => { toast.classList.add('-translate-y-full', 'opacity-0'); setTimeout(() => toast.remove(), 300); }, 3500);
        }

        // Update cart badge
        function updateCartBadge(count) {
            const safeCount = Number.isFinite(Number(count)) ? Number(count) : 0;
            document.querySelectorAll('[data-cart-count]').forEach(el => { el.textContent = String(safeCount).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); });
        }

        // Fetch cart count on load
        fetch('{{ route('cart.count') }}').then(r => r.json()).then(d => updateCartBadge(d.count ?? 0)).catch(() => {});

        // Add to cart
        function addToCart(productId, qty = 1, selectedOptions = null) {
            const payload = { product_id: productId, quantity: qty };
            if (selectedOptions && Object.keys(selectedOptions).length) {
                payload.selected_options = selectedOptions;
            }
            fetch('{{ route('cart.add') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(d => {
                showToast(d.message || 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.', d.success ? 'success' : 'error');
                if (d.success) {
                    updateCartBadge(d.items_count ?? 0);
                    if (typeof loadMiniCart === 'function') loadMiniCart();
                }
            })
            .catch(() => showToast('مشکلی پیش آمد. لطفاً دوباره تلاش کنید.', 'error'));
        }
    </script>
    @stack('scripts')
</body>
</html>
