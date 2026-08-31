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
    <title>@yield('title', 'پنل مدیریت') | گیم‌پک اجاره</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logos/logo-icon.png') }}">

    @include('partials.design-tokens')

    {{-- Admin-only palette extensions layered onto the shared tokens. --}}
    <script>
        tailwind.config.theme.extend.colors.sidebarHover  = '#1a2847';
        tailwind.config.theme.extend.colors.sidebarActive = '#1d3461';
    </script>

    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Vazirmatn', sans-serif; background-color: #F0F4F8 !important; color: #1a202c; }
        .sidebar-link { transition: all 0.15s ease; }
        .sidebar-link:hover { background-color: #1a2847; }
        .sidebar-link.active { background-color: #1d3461; border-right: 3px solid #0066FF; }
        .sidebar-link.disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
        main { overflow-x: hidden; }
        main table { min-width: 720px; }
        main .overflow-x-auto { -webkit-overflow-scrolling: touch; }
        @media (max-width: 1023px) {
            main > div, main section, main form { max-width: 100%; }
            main table { display: table; }
            main .grid { min-width: 0; }
            input, select, textarea, button { max-width: 100%; }
        }
        .admin-table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    </style>

    @stack('styles')
</head>
<body class="antialiased">

{{-- Toast + confirm-dialog mount points. The Store admin had neither and
     used native alert()/confirm(); the public site's toast pattern is
     reused here so admin feedback matches the rest of GamePek. --}}
<div id="admin-toast-container" class="fixed top-5 left-5 z-[100] flex flex-col gap-3 pointer-events-none"></div>
@include('admin.partials.confirm-modal')

<div class="flex h-screen overflow-hidden relative">

    {{-- Mobile Overlay --}}
    <div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden" onclick="closeSidebar()"></div>

    @include('admin.partials.sidebar')

    {{-- ══════════ MAIN AREA ══════════ --}}
    <div class="flex-1 flex flex-col overflow-hidden w-full lg:w-auto">

        @include('admin.partials.topbar')

        {{-- ── Content ── --}}
        <main class="flex-1 overflow-y-auto overflow-x-hidden p-3 md:p-6">

            @include('admin.partials.flash')

            @yield('content')
        </main>
    </div>
</div>

@include('admin.partials.scripts')
@stack('scripts')
</body>
</html>
