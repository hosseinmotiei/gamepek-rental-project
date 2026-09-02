@extends('layouts.app')
@section('title', 'گیم‌پک | پروفایل کاربری')

@push('styles')
<style>
    .tab-active::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 100%; height: 3px; background-color: #0066FF; border-radius: 3px 3px 0 0; }
    .snap-x-mandatory { scroll-snap-type: x mandatory; }
    .snap-center { scroll-snap-align: center; }
    .toggle-checkbox:checked { right: 0; border-color: #0066FF; }
    .toggle-checkbox:checked + .toggle-label { background-color: #0066FF; }
    .toggle-checkbox { right: 0; z-index: 1; border-color: #e2e8f0; transition: all 0.3s; }
    .toggle-label { width: 36px; height: 20px; background-color: #cbd5e1; border-radius: 9999px; transition: all 0.3s; }
</style>
@endpush

@section('content')

{{-- Mobile Header: Summary --}}
<div id="mobile-header-summary" class="md:hidden sticky top-0 z-40 w-full bg-white border-b border-gray-100 flex justify-between items-center px-4 py-3 transition-opacity">
    <button class="text-gray-600 hover:text-brandBlue transition-colors"><i class="fa-solid fa-gear text-xl"></i></button>
    <div class="flex items-center gap-5">
        <button class="text-gray-600 hover:text-brandBlue transition-colors"><i class="fa-solid fa-headset text-xl"></i></button>
        <button class="text-gray-600 hover:text-brandBlue transition-colors relative">
            <i class="fa-regular fa-bell text-xl"></i>
            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-[9px] font-bold w-4 h-4 rounded-full flex items-center justify-center">۸</span>
        </button>
    </div>
</div>

{{-- Mobile Header: Inner Section --}}
<div id="mobile-header-inner" class="md:hidden sticky top-0 z-40 w-full bg-white border-b border-gray-100 hidden items-center px-4 py-4 gap-3 transition-opacity">
    <button onclick="switchProfileTab('summary')" class="text-gray-600 hover:text-brandBlue transition-colors">
        <i class="fa-solid fa-arrow-right text-lg"></i>
    </button>
    <h1 id="mobile-header-title" class="font-bold text-gray-800 text-sm">پروفایل</h1>
</div>

<main class="max-w-[1400px] mx-auto px-4 py-4 md:py-8">
    <div class="flex flex-col md:flex-row gap-6">

        {{-- ===== SIDEBAR ===== --}}
        <aside id="profile-sidebar" class="w-full md:w-[320px] shrink-0 flex flex-col gap-4 transition-all">

            {{-- User Card --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm relative">
                <button onclick="openEditNameModal()" class="absolute top-4 left-4 text-brandBlue hover:text-blue-700 transition-colors">
                    <i class="fa-solid fa-pencil text-lg"></i>
                </button>
                <div class="flex flex-col items-center md:items-start text-center md:text-right border-b border-gray-100 pb-4 mb-4">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-3 mx-auto md:mx-0 overflow-hidden">
                        @if($user->avatar)
                        <img src="{{ media_url($user->avatar) }}" alt="{{ $user->full_name ?? 'کاربر گیم‌پک' }}" loading="lazy" decoding="async" width="64" height="64" class="w-full h-full rounded-full object-cover">
                        @else
                        <img src="https://placehold.co/100x100/e2e8f0/64748b?text=User" alt="Avatar" loading="lazy" decoding="async" width="64" height="64" class="w-full h-full object-cover">
                        @endif
                    </div>
                    <h1 class="user-name-display text-lg font-black text-gray-800 mb-1">{{ $user->full_name ?? 'کاربر گیم‌پک' }}</h1>
                    <p class="text-sm text-gray-500" dir="ltr">{{ $user->mobile }}</p>
                </div>
                <div class="flex flex-row md:flex-col justify-around md:justify-start gap-0 md:gap-4 text-center md:text-right">
                    <div onclick="switchProfileTab('wallet')" class="flex-1 md:flex-none flex flex-col md:flex-row md:items-center md:justify-between py-1 md:py-0 cursor-pointer">
                        <div class="flex flex-col md:flex-row md:items-center gap-1 md:gap-2 mb-1 md:mb-0">
                            <i class="fa-solid fa-wallet text-gray-400 text-xl md:text-base hidden md:block"></i>
                            <span class="text-[10px] md:text-xs text-gray-500">کیف پول</span>
                        </div>
                        <div class="flex flex-col md:items-end">
                            <span class="text-xs md:text-sm font-bold text-gray-800"><span id="wallet-sidebar-balance">۰</span> <span class="text-[9px] md:text-[10px] font-normal">تومان</span></span>
                            <button onclick="switchProfileTab('wallet')" class="text-[10px] text-brandBlue mt-0.5 hidden md:block">افزایش موجودی <i class="fa-solid fa-angle-left"></i></button>
                        </div>
                    </div>
                    <div class="w-px bg-gray-100 mx-2 md:hidden"></div>
                    <div class="hidden md:block h-px w-full bg-gray-50"></div>
                    <div onclick="alert('این بخش در حال توسعه است.')" class="flex-1 md:flex-none flex flex-col md:flex-row md:items-center md:justify-between py-1 md:py-0 cursor-pointer">
                        <div class="flex flex-col md:flex-row md:items-center gap-1 md:gap-2 mb-1 md:mb-0">
                            <i class="fa-solid fa-star text-yellow-400 text-xl md:text-base hidden md:block"></i>
                            <span class="text-[10px] md:text-xs text-gray-500">گیم‌پک کلاب</span>
                        </div>
                        <div class="flex flex-col md:items-end">
                            <span class="text-xs md:text-sm font-bold text-gray-800">{{ persian_number($user->loyalty_points ?? $user->points ?? 0) }} <span class="text-[9px] md:text-[10px] font-normal">امتیاز</span></span>
                            <button onclick="alert('این بخش در حال توسعه است.')" class="text-[10px] text-brandBlue mt-0.5 hidden md:block">مشاهده ماموریت‌ها <i class="fa-solid fa-angle-left"></i></button>
                        </div>
                    </div>
                    <div class="w-px bg-gray-100 mx-2 md:hidden"></div>
                    <div class="hidden md:block h-px w-full bg-gray-50"></div>
                    <div class="flex-1 md:flex-none flex flex-col md:flex-row md:items-center md:justify-between py-1 md:py-0">
                        <div class="flex flex-col md:flex-row md:items-center gap-1 md:gap-2 mb-1 md:mb-0">
                            <i class="fa-solid fa-medal text-brandBlue text-xl md:text-base hidden md:block"></i>
                            <span class="text-[10px] md:text-xs text-gray-500">سطح کاربری</span>
                        </div>
                        <span class="text-xs md:text-sm font-bold text-gray-800">گیمر</span>
                    </div>
                </div>
            </div>

            {{-- Desktop Nav --}}
            <div class="hidden md:flex bg-white rounded-2xl border border-gray-200 py-2 shadow-sm flex-col overflow-hidden">
                <button onclick="switchProfileTab('summary', this)" data-tab="summary" class="sidebar-link w-full flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors border-b border-gray-50 group relative">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-house w-5 text-center text-gray-400 group-hover:text-brandBlue transition-colors"></i>
                        <span class="text-sm font-medium">خلاصه فعالیت‌ها</span>
                    </div>
                    <i class="fa-solid fa-chevron-left text-[10px] text-gray-300 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                    <div class="active-indicator hidden w-1 h-full bg-brandBlue absolute right-0 top-0"></div>
                </button>
                <button onclick="switchProfileTab('orders', this)" data-tab="orders" class="sidebar-link w-full flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors border-b border-gray-50 group relative">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-bag-shopping w-5 text-center text-gray-400 group-hover:text-brandBlue transition-colors"></i>
                        <span class="text-sm font-medium">سفارش‌ها</span>
                    </div>
                    <i class="fa-solid fa-chevron-left text-[10px] text-gray-300 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                    <div class="active-indicator hidden w-1 h-full bg-brandBlue absolute right-0 top-0"></div>
                </button>
                <button onclick="switchProfileTab('wallet', this)" data-tab="wallet" class="sidebar-link w-full flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors border-b border-gray-50 group relative">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-wallet w-5 text-center text-gray-400 group-hover:text-brandBlue transition-colors"></i>
                        <span class="text-sm font-medium">کیف پول</span>
                    </div>
                    <i class="fa-solid fa-chevron-left text-[10px] text-gray-300 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                    <div class="active-indicator hidden w-1 h-full bg-brandBlue absolute right-0 top-0"></div>
                </button>
                <button onclick="switchProfileTab('lists', this)" data-tab="lists" class="sidebar-link w-full flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors border-b border-gray-50 group relative">
                    <div class="flex items-center gap-3">
                        <i class="fa-regular fa-comment w-5 text-center text-gray-400 group-hover:text-brandBlue transition-colors"></i>
                        <span class="text-sm font-medium">دیدگاه‌ها و پرسش‌ها</span>
                    </div>
                    <i class="fa-solid fa-chevron-left text-[10px] text-gray-300 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                    <div class="active-indicator hidden w-1 h-full bg-brandBlue absolute right-0 top-0"></div>
                </button>
                <button onclick="switchProfileTab('addresses', this)" data-tab="addresses" class="sidebar-link w-full flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors border-b border-gray-50 group relative">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-location-dot w-5 text-center text-gray-400 group-hover:text-brandBlue transition-colors"></i>
                        <span class="text-sm font-medium">آدرس‌ها</span>
                    </div>
                    <i class="fa-solid fa-chevron-left text-[10px] text-gray-300 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                    <div class="active-indicator hidden w-1 h-full bg-brandBlue absolute right-0 top-0"></div>
                </button>
                <button onclick="switchProfileTab('messages', this)" data-tab="messages" class="sidebar-link w-full flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors border-b border-gray-50 group relative">
                    <div class="flex items-center gap-3">
                        <i class="fa-regular fa-bell w-5 text-center text-gray-400 group-hover:text-brandBlue transition-colors"></i>
                        <span class="text-sm font-medium">پیام‌ها</span>
                    </div>
                    <i class="fa-solid fa-chevron-left text-[10px] text-gray-300 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                    <div class="active-indicator hidden w-1 h-full bg-brandBlue absolute right-0 top-0"></div>
                </button>
                <button onclick="switchProfileTab('recent', this)" data-tab="recent" class="sidebar-link w-full flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors border-b border-gray-50 group relative">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-clock-rotate-left w-5 text-center text-gray-400 group-hover:text-brandBlue transition-colors"></i>
                        <span class="text-sm font-medium">بازدیدهای اخیر</span>
                    </div>
                    <i class="fa-solid fa-chevron-left text-[10px] text-gray-300 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                    <div class="active-indicator hidden w-1 h-full bg-brandBlue absolute right-0 top-0"></div>
                </button>
                <button onclick="switchProfileTab('account', this)" data-tab="account" class="sidebar-link w-full flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors border-b border-gray-50 group relative">
                    <div class="flex items-center gap-3">
                        <i class="fa-regular fa-user w-5 text-center text-gray-400 group-hover:text-brandBlue transition-colors"></i>
                        <span class="text-sm font-medium">اطلاعات حساب کاربری</span>
                    </div>
                    <i class="fa-solid fa-chevron-left text-[10px] text-gray-300 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                    <div class="active-indicator hidden w-1 h-full bg-brandBlue absolute right-0 top-0"></div>
                </button>
                <button onclick="openLogoutModal()" class="w-full flex items-center gap-3 px-5 py-3.5 hover:bg-red-50 text-red-500 transition-colors mt-2">
                    <i class="fa-solid fa-arrow-right-from-bracket w-5 text-center"></i>
                    <span class="text-sm font-bold">خروج از حساب کاربری</span>
                </button>
            </div>
        </aside>

        {{-- ===== MAIN CONTENT ===== --}}
        <div class="flex-1 flex flex-col gap-4 md:gap-6 relative">

            {{-- ────── SECTION 1: SUMMARY ────── --}}
            <div id="sec-summary" class="profile-section flex flex-col gap-4 md:gap-6">

                {{-- Orders Box --}}
                <div class="bg-white rounded-2xl border border-gray-200 p-4 md:p-6 shadow-sm">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="font-bold text-gray-800 text-sm md:text-base border-b-2 border-brandBlue pb-2 inline-block">سفارش‌های من</h2>
                        <button onclick="switchProfileTab('orders')" class="text-xs md:text-sm text-brandBlue font-bold hover:underline flex items-center gap-1">مشاهده همه <i class="fa-solid fa-chevron-left text-[10px]"></i></button>
                    </div>
                    <div class="flex justify-around md:justify-start md:gap-12 text-center md:text-right">
                        <button onclick="switchProfileTab('orders'); switchInnerTab('orders', 'tab-order-current', document.getElementById('btn-order-current'))" class="flex flex-col md:flex-row items-center gap-2 md:gap-4 cursor-pointer group">
                            <div class="w-14 h-14 md:w-16 md:h-16 relative rounded-2xl bg-blue-50 text-brandBlue flex items-center justify-center drop-shadow-sm group-hover:-translate-y-1 transition-transform">
                                <i class="fa-solid fa-truck-fast text-2xl md:text-3xl"></i>
                                <span class="absolute -bottom-1 -right-1 bg-brandBlue text-white text-[10px] md:text-xs font-bold px-2 py-0.5 rounded-md border border-white">{{ persian_number($orderCounts['active']) }}</span>
                            </div>
                            <div class="text-center md:text-right mt-1 md:mt-0">
                                <span class="block text-xs md:text-sm text-gray-600 mb-0.5">سفارش</span>
                                <span class="block text-xs md:text-sm font-bold text-gray-800">جاری</span>
                            </div>
                        </button>
                        <button onclick="switchProfileTab('orders'); switchInnerTab('orders', 'tab-order-delivered', document.getElementById('btn-order-delivered'))" class="flex flex-col md:flex-row items-center gap-2 md:gap-4 cursor-pointer group">
                            <div class="w-14 h-14 md:w-16 md:h-16 relative rounded-2xl bg-green-50 text-green-600 flex items-center justify-center drop-shadow-sm group-hover:-translate-y-1 transition-transform">
                                <i class="fa-solid fa-circle-check text-2xl md:text-3xl"></i>
                                <span class="absolute -bottom-1 -right-1 bg-green-500 text-white text-[10px] md:text-xs font-bold px-2 py-0.5 rounded-md border border-white">{{ persian_number($orderCounts['delivered']) }}</span>
                            </div>
                            <div class="text-center md:text-right mt-1 md:mt-0">
                                <span class="block text-xs md:text-sm text-gray-600 mb-0.5">سفارش</span>
                                <span class="block text-xs md:text-sm font-bold text-gray-800">تحویل شده</span>
                            </div>
                        </button>
                        <button onclick="switchProfileTab('orders'); switchInnerTab('orders', 'tab-order-returned', document.getElementById('btn-order-returned'))" class="flex flex-col md:flex-row items-center gap-2 md:gap-4 cursor-pointer group">
                            <div class="w-14 h-14 md:w-16 md:h-16 relative rounded-2xl bg-yellow-50 text-yellow-600 flex items-center justify-center drop-shadow-sm group-hover:-translate-y-1 transition-transform">
                                <i class="fa-solid fa-rotate-left text-2xl md:text-3xl"></i>
                                <span class="absolute -bottom-1 -right-1 bg-yellow-500 text-white text-[10px] md:text-xs font-bold px-2 py-0.5 rounded-md border border-white">{{ persian_number($orderCounts['returned']) }}</span>
                            </div>
                            <div class="text-center md:text-right mt-1 md:mt-0">
                                <span class="block text-xs md:text-sm text-gray-600 mb-0.5">سفارش</span>
                                <span class="block text-xs md:text-sm font-bold text-gray-800">مرجوع شده</span>
                            </div>
                        </button>
                    </div>
                </div>

                {{-- Mobile Nav List --}}
                <div class="md:hidden bg-white rounded-2xl border border-gray-200 py-1 shadow-sm flex flex-col">
                    <button onclick="switchProfileTab('orders')" class="flex items-center justify-between w-full px-4 py-3.5 active:bg-gray-50 text-gray-700 border-b border-gray-100">
                        <span class="text-[13px] font-medium">سفارش‌ها</span>
                        <div class="flex items-center gap-3"><i class="fa-solid fa-bag-shopping text-gray-400"></i><i class="fa-solid fa-chevron-left text-[10px] text-gray-300 w-2"></i></div>
                    </button>
                    <button onclick="switchProfileTab('wallet')" class="flex items-center justify-between w-full px-4 py-3.5 active:bg-gray-50 text-gray-700 border-b border-gray-100">
                        <span class="text-[13px] font-medium">کیف پول</span>
                        <div class="flex items-center gap-3"><i class="fa-solid fa-wallet text-gray-400"></i><i class="fa-solid fa-chevron-left text-[10px] text-gray-300 w-2"></i></div>
                    </button>
                    <button onclick="switchProfileTab('addresses')" class="flex items-center justify-between w-full px-4 py-3.5 active:bg-gray-50 text-gray-700 border-b border-gray-100">
                        <span class="text-[13px] font-medium">آدرس‌ها</span>
                        <div class="flex items-center gap-3"><i class="fa-solid fa-location-dot text-gray-400"></i><i class="fa-solid fa-chevron-left text-[10px] text-gray-300 w-2"></i></div>
                    </button>
                    <button onclick="switchProfileTab('messages')" class="flex items-center justify-between w-full px-4 py-3.5 active:bg-gray-50 text-gray-700 border-b border-gray-100">
                        <span class="text-[13px] font-medium">پیام‌ها</span>
                        <div class="flex items-center gap-3"><i class="fa-regular fa-bell text-gray-400"></i><i class="fa-solid fa-chevron-left text-[10px] text-gray-300 w-2"></i></div>
                    </button>
                    <button onclick="switchProfileTab('recent')" class="flex items-center justify-between w-full px-4 py-3.5 active:bg-gray-50 text-gray-700 border-b border-gray-100">
                        <span class="text-[13px] font-medium">بازدیدهای اخیر</span>
                        <div class="flex items-center gap-3"><i class="fa-solid fa-clock-rotate-left text-gray-400"></i><i class="fa-solid fa-chevron-left text-[10px] text-gray-300 w-2"></i></div>
                    </button>
                    <button onclick="switchProfileTab('account')" class="flex items-center justify-between w-full px-4 py-3.5 active:bg-gray-50 text-gray-700 border-b border-gray-100">
                        <span class="text-[13px] font-medium">اطلاعات حساب کاربری</span>
                        <div class="flex items-center gap-3"><i class="fa-regular fa-user text-gray-400"></i><i class="fa-solid fa-chevron-left text-[10px] text-gray-300 w-2"></i></div>
                    </button>
                    <button onclick="openLogoutModal()" class="flex items-center justify-between w-full px-4 py-4 active:bg-red-50 text-red-500">
                        <span class="text-[13px] font-bold">خروج از حساب کاربری</span>
                        <i class="fa-solid fa-arrow-right-from-bracket text-red-500"></i>
                    </button>
                </div>
            </div>

            {{-- ────── SECTION 2: ORDERS ────── --}}
            <div id="sec-orders" class="profile-section hidden bg-white md:rounded-2xl md:border border-gray-200 shadow-sm flex-col min-h-[600px] w-full">
                <div class="hidden md:flex justify-between items-center px-6 py-5 border-b border-gray-100">
                    <h2 class="font-bold text-gray-800 text-lg">تاریخچه سفارشات</h2>
                    <div class="relative w-64">
                        <i class="fa-solid fa-magnifying-glass absolute right-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" placeholder="جستجو در سفارش‌ها" class="w-full bg-gray-50 text-sm py-2 pr-9 pl-4 rounded-lg border border-gray-200 outline-none focus:border-brandBlue transition-all">
                    </div>
                </div>
                <div class="md:hidden px-4 py-3 border-b border-gray-100">
                    <div class="relative w-full">
                        <i class="fa-solid fa-magnifying-glass absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" placeholder="جستجو در سفارش‌ها" class="w-full bg-gray-100 text-sm py-2.5 pr-9 pl-4 rounded-lg outline-none focus:bg-white focus:border focus:border-brandBlue transition-all">
                    </div>
                </div>

                {{-- Inner Tabs --}}
                <div class="w-full overflow-x-auto hide-scrollbar border-b border-gray-200">
                    <div class="flex min-w-max px-4 md:px-6 inner-tabs-container">
                        <button id="btn-order-current" onclick="switchInnerTab('orders', 'tab-order-current', this)"
                            class="relative py-4 px-3 text-sm font-bold text-brandBlue transition-colors flex items-center gap-1.5 whitespace-nowrap tab-active">
                            <i class="fa-solid fa-truck-fast text-xs"></i>
                            جاری
                            <span class="bg-brandBlue text-white text-[10px] px-1.5 rounded-sm">{{ persian_number($orderCounts['active']) }}</span>
                        </button>
                        <button id="btn-order-delivered" onclick="switchInnerTab('orders', 'tab-order-delivered', this)"
                            class="relative py-4 px-3 text-sm font-medium text-gray-500 hover:text-gray-800 transition-colors flex items-center gap-1.5 whitespace-nowrap">
                            <i class="fa-solid fa-circle-check text-xs"></i>
                            تحویل شده
                            <span class="bg-flashRed text-white text-[10px] px-1.5 rounded-sm">{{ persian_number($orderCounts['delivered']) }}</span>
                        </button>
                        <button id="btn-order-returned" onclick="switchInnerTab('orders', 'tab-order-returned', this)"
                            class="relative py-4 px-3 text-sm text-gray-500 font-medium hover:text-gray-800 transition-colors flex items-center gap-1.5 whitespace-nowrap">
                            <i class="fa-solid fa-rotate-left text-xs"></i>
                            مرجوع شده
                            <span class="bg-gray-200 text-gray-600 text-[10px] px-1.5 rounded-sm">{{ persian_number($orderCounts['returned']) }}</span>
                        </button>
                        <button id="btn-order-cancelled" onclick="switchInnerTab('orders', 'tab-order-cancelled', this)"
                            class="relative py-4 px-3 text-sm text-gray-500 font-medium hover:text-gray-800 transition-colors flex items-center gap-1.5 whitespace-nowrap">
                            <i class="fa-solid fa-circle-xmark text-xs"></i>
                            لغو شده
                            <span class="bg-gray-200 text-gray-600 text-[10px] px-1.5 rounded-sm">{{ persian_number($orderCounts['cancelled']) }}</span>
                        </button>
                    </div>
                </div>

                {{-- Tab: Active Orders --}}
                <div id="tab-order-current" class="orders-content flex flex-col gap-4 p-4 md:p-6">
                    @php $activeOrders = $allOrders->whereIn('status', ['pending_payment', 'paid', 'processing', 'shipped']); @endphp
                    @if($activeOrders->isEmpty())
                    <div class="text-center py-16 flex flex-col items-center">
                        <i class="fa-solid fa-bag-shopping text-5xl text-gray-200 mb-4"></i>
                        <p class="text-gray-500 text-sm mb-4">سفارش جاری ندارید</p>
                        <a href="{{ route('products.index') }}" class="bg-brandBlue text-white font-bold py-2.5 px-6 rounded-xl text-sm hover:bg-blue-600 transition-colors">رفتن به فروشگاه</a>
                    </div>
                    @else
                    @foreach($activeOrders as $order)
                    @php
                        $step = match($order->status) {
                            'pending_payment' => 1,
                            'paid'            => 2,
                            'processing'      => 2,
                            'shipped'         => 3,
                            default           => 1,
                        };
                        $lineWidth = match($step) { 1 => '0%', 2 => '27%', 3 => '57%', default => '0%' };
                    @endphp
                    <div class="border border-brandBlue/30 bg-blue-50/10 rounded-xl p-4 md:p-5 shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-1 h-full bg-brandBlue"></div>
                        <div class="flex justify-between items-start mb-4">
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-spinner animate-spin text-brandBlue text-lg"></i>
                                <span class="font-bold text-brandBlue text-sm md:text-base">{{ $order->status_label ?? 'سفارش جاری' }}</span>
                            </div>
                            <a href="{{ route('orders.show', $order->order_number) }}" class="text-gray-400 hover:text-brandBlue transition-colors text-sm font-bold flex items-center gap-1">پیگیری <i class="fa-solid fa-chevron-left text-[10px]"></i></a>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs md:text-sm text-gray-500 mb-6 border-b border-blue-100 pb-4">
                            <span>{{ $order->created_at->format('Y/m/d') }}</span>
                            <div class="w-1 h-1 bg-gray-300 rounded-full"></div>
                            <span>کد سفارش <span class="text-gray-700 font-medium">{{ $order->order_number }}</span></span>
                            <div class="w-1 h-1 bg-gray-300 rounded-full"></div>
                            <span>مبلغ <span class="text-gray-800 font-bold">{{ persian_number($order->total) }}</span> <span class="text-[10px]">تومان</span></span>
                        </div>

                        {{-- Progress Tracker --}}
                        <div class="mb-8 relative z-0 mt-4 px-2 md:px-8">
                            <div class="absolute top-4 md:top-5 right-[10%] w-[80%] h-1 bg-gray-200 -z-10 rounded-full"></div>
                            <div class="absolute top-4 md:top-5 right-[10%] h-1 bg-brandBlue -z-10 rounded-full transition-all duration-500" style="width: {{ $lineWidth }}"></div>
                            <div class="flex justify-between items-start">
                                <div class="flex flex-col items-center gap-2 w-1/4">
                                    <div class="w-8 h-8 md:w-10 md:h-10 rounded-full {{ $step >= 1 ? 'bg-brandBlue text-white shadow-md' : 'bg-white text-gray-400 border-2 border-gray-200 shadow-sm' }} flex items-center justify-center">
                                        <i class="fa-solid fa-file-invoice text-sm md:text-base"></i>
                                    </div>
                                    <span class="text-[9px] md:text-xs font-{{ $step === 1 ? 'bold text-brandBlue' : ($step > 1 ? 'bold text-gray-800' : 'medium text-gray-400') }} text-center">در حال بررسی</span>
                                </div>
                                <div class="flex flex-col items-center gap-2 w-1/4">
                                    <div class="w-8 h-8 md:w-10 md:h-10 rounded-full {{ $step >= 2 ? 'bg-brandBlue text-white shadow-md' : 'bg-white text-gray-400 border-2 border-gray-200 shadow-sm' }} flex items-center justify-center relative">
                                        @if($step === 2)
                                        <span class="absolute -top-1 -right-1 w-3 h-3 bg-white rounded-full flex items-center justify-center shadow-sm"><span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span></span>
                                        @endif
                                        <i class="fa-solid fa-box-open text-sm md:text-base {{ $step === 2 ? 'mt-1' : '' }}"></i>
                                    </div>
                                    <span class="text-[9px] md:text-xs font-{{ $step === 2 ? 'bold text-brandBlue' : ($step > 2 ? 'bold text-gray-800' : 'medium text-gray-400') }} text-center">در حال آماده‌سازی</span>
                                </div>
                                <div class="flex flex-col items-center gap-2 w-1/4">
                                    <div class="w-8 h-8 md:w-10 md:h-10 rounded-full {{ $step >= 3 ? 'bg-brandBlue text-white shadow-md' : 'bg-white text-gray-400 border-2 border-gray-200 shadow-sm' }} flex items-center justify-center">
                                        <i class="fa-solid fa-truck-fast text-sm md:text-base"></i>
                                    </div>
                                    <span class="text-[9px] md:text-xs font-{{ $step === 3 ? 'bold text-brandBlue' : ($step > 3 ? 'bold text-gray-800' : 'medium text-gray-400') }} text-center">ارسال شد</span>
                                </div>
                                <div class="flex flex-col items-center gap-2 w-1/4">
                                    <div class="w-8 h-8 md:w-10 md:h-10 rounded-full bg-white text-gray-400 flex items-center justify-center border-2 border-gray-200 shadow-sm">
                                        <i class="fa-solid fa-house-circle-check text-sm md:text-base"></i>
                                    </div>
                                    <span class="text-[9px] md:text-xs font-medium text-gray-400 text-center">تحویل داده شد</span>
                                </div>
                            </div>
                        </div>

                        {{-- Address Info --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 bg-white rounded-xl border border-blue-50 p-4 shadow-sm">
                            <div class="flex items-start gap-3">
                                <i class="fa-solid fa-location-dot text-brandBlue mt-1"></i>
                                <div class="flex flex-col gap-1.5">
                                    <span class="text-[10px] text-gray-500 font-bold">آدرس گیرنده</span>
                                    <p class="text-[11px] md:text-xs text-gray-800 leading-relaxed font-medium">{{ $order->receiver_address_text }}</p>
                                    <span class="text-[10px] text-gray-600 mt-1">گیرنده: {{ $order->receiver_name ?? '-' }}@if($order->receiver_mobile) | <span dir="ltr">{{ $order->receiver_mobile }}</span>@endif</span>
                                </div>
                            </div>
                            <div class="hidden md:block w-px bg-gray-100 mx-auto"></div>
                            <div class="md:hidden h-px bg-gray-100 w-full my-1"></div>
                            <div class="flex items-start gap-3">
                                <i class="fa-solid fa-shop text-brandBlue mt-1"></i>
                                <div class="flex flex-col gap-1.5">
                                    <span class="text-[10px] text-gray-500 font-bold">مبدأ ارسال</span>
                                    <p class="text-[11px] md:text-xs text-gray-800 leading-relaxed font-medium">تهران، سعادت‌آباد</p>
                                    <span class="text-[10px] text-gray-600 mt-1">ارسال توسط: پیک اختصاصی گیم‌پک (اکسپرس)</span>
                                </div>
                            </div>
                        </div>

                        {{-- Items --}}
                        <div class="flex items-center justify-between pt-4 border-t border-blue-100">
                            <div class="flex gap-2 overflow-x-auto hide-scrollbar max-w-[70%]">
                                @foreach($order->items->take(4) as $item)
                                <div class="w-12 h-12 md:w-16 md:h-16 bg-white rounded-lg flex items-center justify-center shrink-0 border border-gray-100 p-1 shadow-sm">
                                    <img src="{{ media_url($item->product?->main_image, 'https://placehold.co/100x100/FFFFFF/111111?text=Item') }}" alt="{{ $item->product->title_fa ?? 'محصول' }}" loading="lazy" decoding="async" width="64" height="64" class="w-full h-full object-contain">
                                </div>
                                @endforeach
                            </div>
                            <a href="{{ route('orders.show', $order->order_number) }}" class="text-brandBlue text-xs md:text-sm font-bold hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors">مشاهده فاکتور</a>
                        </div>
                    </div>
                    @endforeach
                    @endif
                </div>

                {{-- Tab: Delivered --}}
                <div id="tab-order-delivered" class="orders-content hidden flex-col gap-4 p-4 md:p-6">
                    @php $deliveredOrders = $allOrders->where('status', 'delivered'); @endphp
                    @if($deliveredOrders->isEmpty())
                    <div class="text-center py-16 flex flex-col items-center">
                        <i class="fa-solid fa-circle-check text-5xl text-gray-200 mb-4"></i>
                        <p class="text-gray-500 text-sm">سفارش تحویل‌شده‌ای وجود ندارد</p>
                    </div>
                    @else
                    @foreach($deliveredOrders as $order)
                    <div class="border border-gray-200 rounded-xl p-4 md:p-5 shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-1 h-full bg-green-400 rounded-r-xl"></div>
                        <div class="flex justify-between items-center mb-3 pr-2">
                            <span class="font-bold text-green-600 text-sm flex items-center gap-2"><i class="fa-solid fa-circle-check"></i> تحویل داده شد</span>
                            <a href="{{ route('orders.show', $order->order_number) }}" class="text-brandBlue text-xs font-bold hover:underline">جزئیات</a>
                        </div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 pr-2">
                            <span>{{ $order->created_at->format('Y/m/d') }}</span>
                            <span>کد: <span class="text-gray-700 font-medium">{{ $order->order_number }}</span></span>
                            <span>{{ persian_number($order->total) }} تومان</span>
                        </div>
                    </div>
                    @endforeach
                    @endif
                </div>

                {{-- Tab: Returned --}}
                <div id="tab-order-returned" class="orders-content hidden flex-col gap-4 p-4 md:p-6">
                    @php $returnedOrders = $allOrders->where('status', 'refunded'); @endphp
                    @if($returnedOrders->isEmpty())
                    <div class="text-center py-16 flex flex-col items-center">
                        <i class="fa-solid fa-rotate-left text-5xl text-gray-200 mb-4"></i>
                        <p class="text-gray-500 text-sm">سفارش مرجوع‌شده‌ای وجود ندارد</p>
                    </div>
                    @else
                    @foreach($returnedOrders as $order)
                    <div class="border border-gray-200 rounded-xl p-4 md:p-5 shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-1 h-full bg-yellow-400 rounded-r-xl"></div>
                        <div class="flex justify-between items-center mb-3 pr-2">
                            <span class="font-bold text-yellow-600 text-sm flex items-center gap-2"><i class="fa-solid fa-rotate-left"></i> مرجوع شد</span>
                            <a href="{{ route('orders.show', $order->order_number) }}" class="text-brandBlue text-xs font-bold hover:underline">جزئیات</a>
                        </div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 pr-2">
                            <span>{{ $order->created_at->format('Y/m/d') }}</span>
                            <span>کد: <span class="text-gray-700 font-medium">{{ $order->order_number }}</span></span>
                            <span>{{ persian_number($order->total) }} تومان</span>
                        </div>
                    </div>
                    @endforeach
                    @endif
                </div>

                {{-- Tab: Cancelled --}}
                <div id="tab-order-cancelled" class="orders-content hidden flex-col gap-4 p-4 md:p-6">
                    @php $cancelledOrders = $allOrders->where('status', 'cancelled'); @endphp
                    @if($cancelledOrders->isEmpty())
                    <div class="text-center py-16 flex flex-col items-center">
                        <i class="fa-solid fa-circle-xmark text-5xl text-gray-200 mb-4"></i>
                        <p class="text-gray-500 text-sm">سفارش لغوشده‌ای وجود ندارد</p>
                    </div>
                    @else
                    @foreach($cancelledOrders as $order)
                    <div class="border border-gray-200 rounded-xl p-4 md:p-5 shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-1 h-full bg-red-400 rounded-r-xl"></div>
                        <div class="flex justify-between items-center mb-3 pr-2">
                            <span class="font-bold text-red-500 text-sm flex items-center gap-2"><i class="fa-solid fa-circle-xmark"></i> لغو شد</span>
                            <a href="{{ route('orders.show', $order->order_number) }}" class="text-brandBlue text-xs font-bold hover:underline">جزئیات</a>
                        </div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 pr-2">
                            <span>{{ $order->created_at->format('Y/m/d') }}</span>
                            <span>کد: <span class="text-gray-700 font-medium">{{ $order->order_number }}</span></span>
                            <span>{{ persian_number($order->total) }} تومان</span>
                        </div>
                    </div>
                    @endforeach
                    @endif
                </div>
            </div>

            {{-- ────── SECTION 4: WALLET ──────
                 Frontend/design prototype only. There is no wallet_balance
                 column on users yet, so balance starts at 0 and lives
                 entirely in localStorage; top-up/withdraw/history are mock,
                 client-side-only state with no backend wiring. Replace this
                 with real API calls (and a real balance column) when the
                 wallet backend ships. --}}
            @php
                $walletId = 'GP-' . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
            @endphp
            <div id="sec-wallet" class="profile-section hidden bg-white md:rounded-2xl md:border border-gray-200 shadow-sm flex-col min-h-[600px] w-full">
                <div class="hidden md:flex justify-between items-center px-6 py-5 border-b border-gray-100">
                    <h2 class="font-bold text-gray-800 text-lg">کیف پول</h2>
                </div>

                <div class="p-4 md:p-6 flex flex-col gap-5 max-w-xl">

                    {{-- Balance Card --}}
                    <div class="bg-gradient-to-l from-brandBlue to-blue-700 rounded-2xl p-5 md:p-6 text-white shadow-lg shadow-blue-500/20">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-medium text-blue-100">موجودی کیف پول</p>
                                <p class="mt-1.5 flex items-baseline gap-2 leading-none">
                                    <span id="wallet-balance-amount" class="text-2xl md:text-3xl font-black tracking-tight tabular-nums">••••••</span>
                                    <span id="wallet-balance-currency" class="text-xs md:text-sm font-semibold opacity-80 hidden">تومان</span>
                                </p>
                            </div>
                            <button type="button" onclick="toggleWalletBalance()" id="wallet-hide-toggle" class="w-10 h-10 shrink-0 flex items-center justify-center rounded-lg hover:bg-white/10 transition-colors" aria-label="نمایش/مخفی‌کردن موجودی">
                                <i class="fa-solid fa-eye text-base"></i>
                            </button>
                        </div>
                        <div class="mt-4 flex items-center justify-between gap-3 border-t border-white/20 pt-3">
                            <div class="flex items-center gap-2 text-xs text-blue-100">
                                <i class="fa-solid fa-wallet"></i>
                                <span>شناسه کیف پول</span>
                                <span class="font-bold tracking-wide text-white" dir="ltr">{{ $walletId }}</span>
                            </div>
                            <button type="button" onclick="copyWalletId('{{ $walletId }}')" id="wallet-copy-btn" class="w-9 h-9 flex items-center justify-center rounded-md hover:bg-white/10 transition-colors" aria-label="کپی شناسه کیف پول">
                                <i class="fa-regular fa-copy text-sm"></i>
                            </button>
                        </div>
                    </div>

                    {{-- Segmented Tabs --}}
                    <div class="grid grid-cols-3 gap-1 rounded-xl bg-gray-100 p-1">
                        <button type="button" onclick="switchWalletTab('topup', this)" data-wallet-tab="topup" class="wallet-tab-btn h-11 rounded-lg text-[11px] md:text-sm font-bold transition-colors">افزایش موجودی</button>
                        <button type="button" onclick="switchWalletTab('withdraw', this)" data-wallet-tab="withdraw" class="wallet-tab-btn h-11 rounded-lg text-[11px] md:text-sm font-bold transition-colors">برداشت وجه</button>
                        <button type="button" onclick="switchWalletTab('history', this)" data-wallet-tab="history" class="wallet-tab-btn h-11 rounded-lg text-[11px] md:text-sm font-bold transition-colors">تراکنش‌ها</button>
                    </div>

                    {{-- Top Up / Withdraw shared panel --}}
                    <div id="wallet-panel-form" class="bg-gray-50 rounded-2xl border border-gray-100 p-4 md:p-5 flex flex-col">
                        <h3 id="wallet-form-title" class="text-sm md:text-base font-bold text-gray-800">افزایش موجودی کیف پول</h3>
                        <p id="wallet-form-hint" class="mt-1 text-xs md:text-sm text-gray-500">مبلغ مورد نظر برای افزایش موجودی را انتخاب کنید.</p>
                        <p id="wallet-form-eta" class="mt-2 text-[11px] md:text-xs font-bold text-brandBlue">واریز آنی به کیف پول</p>

                        <div class="mt-4 grid grid-cols-3 gap-2" id="wallet-preset-grid"></div>

                        <label class="mt-4 block">
                            <span class="mb-1.5 block text-xs font-bold text-gray-500">مبلغ دلخواه</span>
                            <div class="flex h-12 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 focus-within:border-brandBlue transition-colors">
                                <input type="text" inputmode="numeric" id="wallet-custom-amount" placeholder="حداقل ۵۰,۰۰۰" class="h-full min-w-0 flex-1 bg-transparent text-sm md:text-base font-bold tabular-nums outline-none text-gray-800">
                                <span class="text-xs font-medium text-gray-400 shrink-0">تومان</span>
                            </div>
                        </label>

                        <div id="wallet-withdraw-fields" class="hidden">
                            <label id="wallet-card-field" class="mt-4 block">
                                <span class="mb-1.5 block text-xs font-bold text-gray-500">شماره کارت بانکی</span>
                                <div class="flex h-12 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 focus-within:border-brandBlue transition-colors">
                                    <i class="fa-solid fa-credit-card text-brandBlue shrink-0"></i>
                                    <input type="text" inputmode="numeric" autocomplete="off" id="wallet-card-number" placeholder="•••• •••• •••• ••••" dir="ltr" class="h-full min-w-0 flex-1 bg-transparent text-sm md:text-base font-bold tabular-nums tracking-wide outline-none text-gray-800">
                                </div>
                            </label>

                            <label id="wallet-sheba-field" class="mt-4 block">
                                <span class="mb-1.5 block text-xs font-bold text-gray-500">شماره شبا</span>
                                <div class="flex h-12 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 focus-within:border-brandBlue transition-colors">
                                    <i class="fa-solid fa-building-columns text-brandBlue shrink-0"></i>
                                    <span class="text-sm font-bold text-gray-400 shrink-0">IR</span>
                                    <input type="text" inputmode="numeric" autocomplete="off" id="wallet-sheba-number" placeholder="•••• •••• •••• •••• •••" dir="ltr" class="h-full min-w-0 flex-1 bg-transparent text-sm md:text-base font-bold tabular-nums tracking-wide outline-none text-gray-800">
                                </div>
                            </label>

                            <p class="mt-3 text-xs text-gray-500 leading-5">شماره کارت و شماره شبای واردشده باید به نام صاحب حساب کاربری باشد.</p>
                        </div>

                        <p id="wallet-form-error" class="mt-3 text-xs md:text-sm font-medium text-flashRed hidden"></p>

                        <button type="button" onclick="openWalletConfirm()" class="mt-5 h-12 w-full flex items-center justify-center rounded-xl bg-brandBlue text-white text-sm font-bold shadow-md shadow-blue-500/20 hover:bg-blue-600 transition-colors">ادامه</button>
                    </div>

                    {{-- History panel --}}
                    <div id="wallet-panel-history" class="hidden bg-white rounded-2xl border border-gray-100">
                        <ul id="wallet-tx-list" class="divide-y divide-gray-100"></ul>
                        <div id="wallet-tx-empty" class="hidden text-center py-16 flex-col items-center">
                            <i class="fa-solid fa-wallet text-5xl text-gray-200 mb-4"></i>
                            <p class="text-gray-500 text-sm mb-1">هنوز تراکنشی ثبت نشده است</p>
                            <p class="text-gray-400 text-xs">با افزایش موجودی، اولین تراکنش شما اینجا نمایش داده می‌شود</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ===== MODAL: WALLET CONFIRM ===== --}}
            <div id="wallet-confirm-modal" class="fixed inset-0 bg-black/60 z-[80] hidden flex items-end md:items-center justify-center transition-opacity duration-300 opacity-0 md:px-4">
                <div class="bg-white w-full md:max-w-sm rounded-t-2xl md:rounded-2xl flex flex-col shadow-2xl transform scale-95 transition-transform duration-300">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                        <h3 id="wallet-confirm-title" class="font-bold text-gray-800 text-sm">تایید افزایش موجودی</h3>
                        <button onclick="closeWalletConfirm()" class="text-gray-400 hover:text-gray-800"><i class="fa-solid fa-xmark text-lg"></i></button>
                    </div>
                    <div class="p-5 flex flex-col gap-3 text-sm">
                        <div class="flex items-start justify-between gap-4">
                            <span id="wallet-confirm-amount-label" class="text-gray-500">مبلغ پرداختی</span>
                            <span id="wallet-confirm-amount" class="font-bold tabular-nums text-gray-800"></span>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <span id="wallet-confirm-dest-label" class="text-gray-500">مبدأ</span>
                            <span id="wallet-confirm-dest" class="font-bold text-gray-800"></span>
                        </div>
                        <div id="wallet-confirm-sheba-row" class="hidden flex items-start justify-between gap-4">
                            <span class="text-gray-500">شماره شبا</span>
                            <span id="wallet-confirm-sheba" class="font-bold text-gray-800" dir="ltr"></span>
                        </div>
                        <div class="flex items-start justify-between gap-4 pt-3 border-t border-gray-100">
                            <span class="text-gray-500">موجودی جدید</span>
                            <span id="wallet-confirm-new-balance" class="font-bold tabular-nums text-gray-800"></span>
                        </div>
                    </div>
                    <div class="p-5 border-t border-gray-100 flex gap-3">
                        <button id="wallet-confirm-submit-btn" onclick="commitWalletTx()" class="flex-1 bg-brandBlue text-white font-bold py-2.5 rounded-xl text-sm shadow-md hover:bg-blue-600 transition-colors">تایید</button>
                        <button onclick="closeWalletConfirm()" class="flex-1 bg-gray-100 text-gray-600 font-bold py-2.5 rounded-xl text-sm hover:bg-gray-200 transition-colors">انصراف</button>
                    </div>
                </div>
            </div>

            {{-- ────── SECTION 5: ADDRESSES ────── --}}
            <div id="sec-addresses" class="profile-section hidden bg-white md:rounded-2xl md:border border-gray-200 shadow-sm flex-col min-h-[600px] w-full relative pb-20 md:pb-0">
                <div class="hidden md:flex justify-between items-center px-6 py-5 border-b border-gray-100">
                    <h2 class="font-bold text-gray-800 text-lg">آدرس‌ها</h2>
                    <button onclick="openAddressModal()" class="flex items-center gap-2 text-sm text-white bg-brandBlue font-bold px-4 py-2.5 rounded-xl transition-colors shadow-md hover:bg-blue-600">
                        <i class="fa-solid fa-location-dot"></i> ثبت آدرس جدید
                    </button>
                </div>
                <div class="flex flex-col">
                    <button class="flex items-center justify-center gap-3 py-4 md:py-6 border-b border-gray-100 text-gray-700 hover:bg-gray-50 transition-colors w-full">
                        <i class="fa-solid fa-crosshairs text-lg"></i>
                        <span class="text-sm font-bold">انتخاب موقعیت از نقشه</span>
                    </button>
                    <div class="p-4 md:p-6">
                        @if($addresses->isEmpty())
                        <div class="text-center py-16 flex flex-col items-center">
                            <i class="fa-solid fa-location-dot text-5xl text-gray-200 mb-4"></i>
                            <p class="text-gray-500 text-sm mb-4">هیچ آدرسی ثبت نکرده‌اید</p>
                            <button onclick="openAddressModal()" class="bg-brandBlue text-white font-bold py-2.5 px-6 rounded-xl text-sm hover:bg-blue-600 transition-colors">افزودن آدرس</button>
                        </div>
                        @else
                        <h3 class="text-sm font-bold text-gray-800 mb-4 hidden md:block">آدرس‌های من</h3>
                        <div class="flex flex-col gap-0 divide-y divide-gray-100 border-b border-gray-100 md:border-none md:divide-none md:gap-4">
                            @foreach($addresses as $address)
                            <div class="flex items-start gap-3 py-4 md:p-5 md:border border-gray-200 md:rounded-xl relative group hover:bg-gray-50 transition-colors" data-address-id="{{ $address->id }}">
                                @if($address->is_default)
                                <span class="absolute top-4 left-10 md:top-3 md:left-14 bg-blue-100 text-brandBlue text-[10px] font-bold px-2 py-0.5 rounded-full">پیش‌فرض</span>
                                @endif
                                <button class="absolute left-0 top-4 md:left-4 text-gray-400 hover:text-gray-700 p-2"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                <div class="w-6 shrink-0 mt-0.5 text-gray-400 flex justify-center">
                                    <i class="fa-solid fa-location-dot text-lg"></i>
                                </div>
                                <div class="flex flex-col flex-1 pl-6">
                                    <p class="text-xs md:text-sm text-gray-800 font-medium leading-loose mb-3">{{ $address->province }}، {{ $address->city }}، {{ $address->address_line }} پلاک {{ $address->plaque }}</p>
                                    <div class="flex flex-col gap-2 text-[10px] md:text-xs text-gray-500">
                                        @if($address->postal_code)
                                        <div class="flex items-center gap-2">
                                            <i class="fa-regular fa-envelope text-gray-400 w-4 text-center"></i>
                                            <span>کد پستی: {{ $address->postal_code }}</span>
                                        </div>
                                        @endif
                                        <div class="flex items-center gap-2">
                                            <i class="fa-regular fa-user text-gray-400 w-4 text-center"></i>
                                            <span>گیرنده: {{ $address->receiver_name }} | {{ $address->receiver_mobile }}</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4 mt-3 pt-3 border-t border-gray-50">
                                        @if(!$address->is_default)
                                        <button onclick="setDefaultAddress({{ $address->id }})" class="text-[10px] text-brandBlue font-bold hover:underline">انتخاب پیش‌فرض</button>
                                        @endif
                                        <button onclick="deleteAddress({{ $address->id }}, this)" class="text-[10px] text-red-400 hover:text-red-600 font-bold hover:underline">حذف</button>
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>
                <button onclick="openAddressModal()" class="md:hidden fixed bottom-[85px] left-4 bg-brandBlue text-white rounded-full px-4 py-3 flex items-center justify-center gap-2 z-40 shadow-[0_4px_12px_rgba(0,102,255,0.35)] font-bold text-xs">
                    <i class="fa-solid fa-plus text-sm"></i> ثبت آدرس جدید
                </button>
            </div>

            {{-- ────── SECTION 6: MESSAGES ────── --}}
            <div id="sec-messages" class="profile-section hidden bg-white md:rounded-2xl md:border border-gray-200 shadow-sm flex-col min-h-[600px] w-full">
                <div class="hidden md:flex justify-between items-center px-6 py-5 border-b border-gray-100">
                    <h2 class="font-bold text-gray-800 text-lg">پیام‌ها</h2>
                    <a href="{{ route('messages.create') }}" class="text-xs md:text-sm text-brandBlue font-bold hover:underline flex items-center gap-1"><i class="fa-solid fa-plus"></i> گفتگوی جدید</a>
                </div>
                <div class="flex flex-col divide-y divide-gray-100" id="messages-list">
                    @forelse($conversations as $conversation)
                    <a href="{{ route('messages.show', $conversation) }}" class="flex items-center justify-between gap-3 px-4 md:px-6 py-4 hover:bg-gray-50 transition-colors">
                        <div class="flex items-center gap-3">
                            @if($conversation->isUnreadByUser())
                            <span class="w-2 h-2 rounded-full bg-brandBlue shrink-0"></span>
                            @endif
                            <div>
                                <span class="text-sm text-gray-800 {{ $conversation->isUnreadByUser() ? 'font-bold' : 'font-medium' }}">{{ $conversation->subject }}</span>
                                <p class="text-[11px] text-gray-400 mt-0.5">{{ $conversation->last_message_at?->diffForHumans() }}</p>
                            </div>
                        </div>
                        <span class="text-[10px] px-2.5 py-1 rounded-full font-bold shrink-0 {{ $conversation->status === 'open' ? 'bg-blue-100 text-brandBlue' : 'bg-gray-100 text-gray-500' }}">
                            {{ $conversation->status_label }}
                        </span>
                    </a>
                    @empty
                    <div class="flex flex-col items-center justify-center py-20 px-6 text-center">
                        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                            <i class="fa-regular fa-comment-dots text-3xl text-gray-300"></i>
                        </div>
                        <p class="text-sm font-medium text-gray-500 mb-1">هنوز گفتگویی با پشتیبانی ثبت نکرده‌اید.</p>
                        <a href="{{ route('messages.create') }}" class="text-xs text-brandBlue font-bold hover:underline mt-2">شروع گفتگوی جدید</a>
                    </div>
                    @endforelse
                </div>
                @if($conversations->isNotEmpty())
                <div class="px-4 md:px-6 py-4 text-center border-t border-gray-100">
                    <a href="{{ route('messages.index') }}" class="text-xs md:text-sm text-brandBlue font-bold hover:underline">مشاهده همه پیام‌ها <i class="fa-solid fa-chevron-left text-[10px]"></i></a>
                </div>
                @endif
            </div>

            {{-- ────── SECTION 7: RECENT VIEWS ────── --}}
            <div id="sec-recent" class="profile-section hidden bg-white md:rounded-2xl md:border border-gray-200 shadow-sm flex-col min-h-[600px] w-full">
                <div class="hidden md:flex justify-between items-center px-6 py-5 border-b border-gray-100">
                    <h2 class="font-bold text-gray-800 text-lg">بازدیدهای اخیر</h2>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-0 p-0 md:p-4 divide-y divide-x divide-x-reverse divide-gray-100 md:divide-none md:gap-4">
                    <div class="flex flex-col items-center justify-center p-8 col-span-2 md:col-span-4 min-h-[300px]">
                        <i class="fa-solid fa-clock-rotate-left text-6xl text-gray-200 mb-4"></i>
                        <p class="text-gray-500 font-medium">بازدیدی ثبت نشده است.</p>
                    </div>
                </div>
            </div>

            {{-- ────── SECTION 8: ACCOUNT INFO ────── --}}
            <div id="sec-account" class="profile-section hidden bg-white md:rounded-2xl md:border border-gray-200 shadow-sm flex-col min-h-[600px] w-full">
                <div class="hidden md:flex justify-between items-center px-6 py-5 border-b border-gray-100">
                    <h2 class="font-bold text-gray-800 text-lg">اطلاعات حساب کاربری</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-0 p-4 md:p-6">
                    <div class="flex items-center justify-between py-4 border-b border-gray-100">
                        <div class="flex flex-col gap-1">
                            <span class="text-[10px] md:text-xs text-gray-400">نام و نام خانوادگی</span>
                            <span class="user-name-display text-sm md:text-base font-bold text-gray-800">{{ $user->full_name ?? '-' }}</span>
                        </div>
                        <button onclick="openEditNameModal()" class="text-brandBlue p-2"><i class="fa-solid fa-pencil"></i></button>
                    </div>
                    <div class="flex items-center justify-between py-4 border-b border-gray-100">
                        <div class="flex flex-col gap-1">
                            <span class="text-[10px] md:text-xs text-gray-400">شماره موبایل</span>
                            <span class="text-sm md:text-base font-bold text-gray-800" dir="ltr">{{ $user->mobile }}</span>
                        </div>
                    </div>
                    <div class="flex items-center justify-between py-4 border-b border-gray-100">
                        <div class="flex flex-col gap-1">
                            <span class="text-[10px] md:text-xs text-gray-400">کد ملی</span>
                            <span class="text-sm md:text-base font-bold text-gray-800">{{ $user->national_code ?? '-' }}</span>
                        </div>
                        <button class="text-brandBlue p-2"><i class="fa-solid fa-plus"></i></button>
                    </div>
                    <div class="flex items-center justify-between py-4 border-b border-gray-100">
                        <div class="flex flex-col gap-1">
                            <span class="text-[10px] md:text-xs text-gray-400">رمز عبور</span>
                            <span class="text-sm md:text-base font-bold text-gray-800">••••••••</span>
                        </div>
                        <button class="text-brandBlue p-2"><i class="fa-solid fa-pencil"></i></button>
                    </div>
                    <div class="flex items-center justify-between py-4 border-b border-gray-100">
                        <div class="flex flex-col gap-1">
                            <span class="text-[10px] md:text-xs text-gray-400">ایمیل</span>
                            <span class="text-sm md:text-base font-bold text-gray-800" dir="ltr">{{ $user->email ?? '-' }}</span>
                        </div>
                        <button class="text-brandBlue p-2"><i class="fa-solid fa-plus"></i></button>
                    </div>
                    <div class="flex items-center justify-between py-4 border-b border-gray-100">
                        <div class="flex flex-col gap-1">
                            <span class="text-[10px] md:text-xs text-gray-400">تاریخ تولد</span>
                            <span class="text-sm md:text-base font-bold text-gray-800">{{ $user->birth_date ?? '-' }}</span>
                        </div>
                        <button class="text-brandBlue p-2"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
            </div>

        </div>{{-- end main content --}}
    </div>
</main>

{{-- ===== MODAL: ADDRESS ===== --}}
<div id="address-modal" class="fixed inset-0 bg-white md:bg-black/60 z-[70] hidden flex-col md:items-center md:justify-center transition-opacity duration-300 opacity-0">
    <div class="bg-white w-full h-full md:h-auto md:w-[500px] md:max-h-[90vh] md:rounded-2xl flex flex-col transform md:scale-95 transition-transform duration-300 shadow-2xl">
        <div class="flex items-center justify-between px-4 py-4 border-b border-gray-100 shrink-0">
            <button onclick="closeAddressModal()" class="text-gray-500 hover:text-gray-800 p-1"><i class="fa-solid fa-xmark text-lg"></i></button>
            <h3 class="font-bold text-gray-800 text-sm md:text-base mx-auto pr-4">افزودن آدرس جدید</h3>
        </div>
        <div class="flex-1 overflow-y-auto p-4 md:p-6 flex flex-col gap-5 hide-scrollbar">
            <div class="flex flex-col gap-2">
                <label class="text-xs text-gray-700 font-bold">نام گیرنده <span class="text-red-500">*</span></label>
                <input type="text" id="addr-receiver-name" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-700 outline-none focus:border-brandBlue">
            </div>
            <div class="flex flex-col gap-2">
                <label class="text-xs text-gray-700 font-bold">موبایل گیرنده <span class="text-red-500">*</span></label>
                <input type="tel" id="addr-receiver-mobile" inputmode="numeric" maxlength="11" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-700 outline-none focus:border-brandBlue" dir="ltr" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            </div>
            <div class="flex flex-col gap-2 relative">
                <label class="text-xs text-gray-700 font-bold">استان <span class="text-red-500">*</span></label>
                <div class="relative">
                    <select id="addr-province" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-700 outline-none focus:border-brandBlue appearance-none bg-gray-50" readonly>
                        <option value="تهران" selected>تهران</option>
                    </select>
                    <i class="fa-solid fa-chevron-down absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                </div>
            </div>
            <div class="flex flex-col gap-2 relative">
                <label class="text-xs text-gray-700 font-bold">شهر <span class="text-red-500">*</span></label>
                <div class="relative">
                    <select id="addr-city" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-700 outline-none focus:border-brandBlue appearance-none bg-gray-50" readonly>
                        <option value="تهران" selected>تهران</option>
                    </select>
                    <i class="fa-solid fa-chevron-down absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                </div>
            </div>
            <div class="flex flex-col gap-2">
                <label class="text-xs text-gray-700 font-bold">آدرس <span class="text-red-500">*</span></label>
                <textarea id="addr-address" rows="3" placeholder="مثال: خیابان، کوچه و جزئیات آدرس" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-800 outline-none focus:border-brandBlue resize-none"></textarea>
                <p class="text-[10px] text-gray-400 leading-relaxed mt-1">در صورت تغییر این بخش و ناهماهنگی آن با موقعیت مکانی، ممکن است ارسال سفارش با مشکل مواجه شود.</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="flex flex-col gap-2">
                    <label class="text-xs text-gray-700 font-bold">پلاک <span class="text-red-500">*</span></label>
                    <input type="text" id="addr-plaque" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-800 outline-none focus:border-brandBlue bg-gray-50/50">
                </div>
                <div class="flex flex-col gap-2">
                    <label class="text-xs text-gray-700 font-bold">واحد</label>
                    <input type="text" id="addr-unit" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-800 outline-none focus:border-brandBlue bg-gray-50/50">
                </div>
            </div>
            <div class="flex flex-col gap-2">
                <label class="text-xs text-gray-700 font-bold">کد پستی <span class="text-red-500">*</span></label>
                <input type="text" inputmode="numeric" pattern="[0-9]*" id="addr-postal" maxlength="10" placeholder="باید ۱۰ رقمی باشد" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-800 outline-none focus:border-brandBlue bg-gray-50/50" dir="ltr" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            </div>
        </div>
        <div class="p-4 border-t border-gray-100 shrink-0 bg-white">
            <button onclick="saveAddress()" id="save-address-btn" class="w-full bg-brandBlue text-white font-bold py-3.5 rounded-xl shadow-lg shadow-blue-500/20 hover:bg-blue-600 transition-colors text-sm">تایید و ذخیره آدرس</button>
        </div>
    </div>
</div>

{{-- ===== MODAL: EDIT NAME ===== --}}
<div id="edit-name-modal" class="fixed inset-0 bg-black/60 z-[80] hidden flex items-center justify-center transition-opacity duration-300 opacity-0 px-4">
    <div class="bg-white w-full max-w-sm rounded-2xl flex flex-col shadow-2xl transform scale-95 transition-transform duration-300">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h3 class="font-bold text-gray-800 text-sm">ویرایش نام و نام خانوادگی</h3>
            <button onclick="closeEditNameModal()" class="text-gray-400 hover:text-gray-800"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <div class="p-5">
            <div class="flex flex-col gap-2">
                <label class="text-xs text-gray-500">نام و نام خانوادگی جدید</label>
                <input type="text" id="name-input" value="{{ $user->full_name }}"
                       class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-800 outline-none focus:border-brandBlue">
            </div>
        </div>
        <div class="p-5 border-t border-gray-100 flex gap-3">
            <button onclick="saveName()" class="flex-1 bg-brandBlue text-white font-bold py-2.5 rounded-xl text-sm shadow-md hover:bg-blue-600 transition-colors">ثبت تغییرات</button>
            <button onclick="closeEditNameModal()" class="flex-1 bg-gray-100 text-gray-600 font-bold py-2.5 rounded-xl text-sm hover:bg-gray-200 transition-colors">انصراف</button>
        </div>
    </div>
</div>

{{-- ===== MODAL: LOGOUT ===== --}}
<div id="logout-modal" class="fixed inset-0 bg-black/60 z-[80] hidden flex items-center justify-center transition-opacity duration-300 opacity-0 px-4">
    <div class="bg-white w-full max-w-sm rounded-2xl flex flex-col shadow-2xl transform scale-95 transition-transform duration-300">
        <div class="p-6 flex flex-col items-center text-center gap-4">
            <div class="w-16 h-16 bg-red-50 text-red-500 rounded-full flex items-center justify-center mb-2">
                <i class="fa-solid fa-arrow-right-from-bracket text-2xl"></i>
            </div>
            <h3 class="font-bold text-gray-800 text-base">خروج از حساب کاربری</h3>
            <p class="text-sm text-gray-500 leading-relaxed">آیا مطمئن هستید که می‌خواهید از حساب کاربری خود خارج شوید؟</p>
        </div>
        <div class="p-5 border-t border-gray-100 flex gap-3">
            <form action="{{ route('auth.logout') }}" method="POST" class="flex-1">
                @csrf
                <button type="submit" class="w-full bg-red-500 text-white font-bold py-2.5 rounded-xl text-sm shadow-md hover:bg-red-600 transition-colors">بله، خارج می‌شوم</button>
            </form>
            <button onclick="closeLogoutModal()" class="flex-1 bg-gray-100 text-gray-600 font-bold py-2.5 rounded-xl text-sm hover:bg-gray-200 transition-colors">انصراف</button>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function switchProfileTab(tabId, element) {
    document.querySelectorAll('.profile-section').forEach(el => {
        el.classList.add('hidden');
        el.classList.remove('flex', 'flex-col');
    });
    const target = document.getElementById('sec-' + tabId);
    if (target) { target.classList.remove('hidden'); target.classList.add('flex', 'flex-col'); }

    const titles = { orders:'تاریخچه سفارشات', wallet:'کیف پول', lists:'لیست‌ها', comments:'دیدگاه‌ها و پرسش‌ها', addresses:'آدرس‌ها', messages:'پیام‌ها', recent:'بازدیدهای اخیر', account:'اطلاعات حساب کاربری' };
    const sidebar = document.getElementById('profile-sidebar');
    const headerSummary = document.getElementById('mobile-header-summary');
    const headerInner = document.getElementById('mobile-header-inner');
    const headerTitle = document.getElementById('mobile-header-title');

    if (tabId === 'summary') {
        sidebar.classList.remove('hidden');
        sidebar.classList.add('flex');
        headerSummary.classList.remove('hidden'); headerSummary.classList.add('flex');
        headerInner.classList.add('hidden'); headerInner.classList.remove('flex');
    } else {
        sidebar.classList.add('hidden');
        sidebar.classList.remove('flex');
        sidebar.classList.add('md:flex');
        headerSummary.classList.add('hidden'); headerSummary.classList.remove('flex');
        headerInner.classList.remove('hidden'); headerInner.classList.add('flex');
        if (titles[tabId]) headerTitle.innerText = titles[tabId];
    }

    document.querySelectorAll('.sidebar-link').forEach(el => {
        el.classList.remove('bg-blue-50', 'text-brandBlue');
        el.classList.add('hover:bg-gray-50', 'text-gray-700');
        const ind = el.querySelector('.active-indicator'); if (ind) ind.classList.add('hidden');
        const icon = el.querySelector('i');
        if (icon) { icon.classList.remove('text-brandBlue'); icon.classList.add('text-gray-400'); }
        const chevron = el.querySelector('.fa-chevron-left');
        if (chevron && !chevron.parentElement.classList.contains('flex')) { chevron.classList.remove('opacity-100', 'text-brandBlue'); chevron.classList.add('opacity-0', 'text-gray-300'); }
    });
    const activeLink = document.querySelector(`.sidebar-link[data-tab="${tabId}"]`);
    if (activeLink) {
        activeLink.classList.add('bg-blue-50', 'text-brandBlue'); activeLink.classList.remove('hover:bg-gray-50', 'text-gray-700');
        const ind = activeLink.querySelector('.active-indicator'); if (ind) ind.classList.remove('hidden');
        const icon = activeLink.querySelector('i'); if (icon) icon.classList.remove('text-gray-400');
        const chevron = activeLink.querySelector('.fa-chevron-left');
        if (chevron) { chevron.classList.remove('opacity-0', 'text-gray-300'); chevron.classList.add('opacity-100', 'text-brandBlue'); }
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function switchInnerTab(groupClass, targetId, btnElement) {
    document.querySelectorAll('.' + groupClass + '-content').forEach(el => {
        el.classList.add('hidden'); el.classList.remove('flex', 'block', 'flex-col');
    });
    const target = document.getElementById(targetId);
    if (target) { target.classList.remove('hidden'); target.classList.add('flex', 'flex-col'); }
    const container = btnElement.closest('.inner-tabs-container');
    if (container) {
        container.querySelectorAll('button').forEach(btn => {
            btn.classList.remove('tab-active', 'text-brandBlue', 'font-bold');
            btn.classList.add('text-gray-500', 'font-medium');
        });
        btnElement.classList.remove('text-gray-500', 'font-medium');
        btnElement.classList.add('tab-active', 'text-brandBlue', 'font-bold');
    }
}

// Drag-to-scroll for hide-scrollbar elements
document.querySelectorAll('.hide-scrollbar').forEach(slider => {
    let isDown = false, startX, scrollLeft;
    slider.addEventListener('mousedown', e => { isDown = true; slider.classList.add('cursor-grabbing'); startX = e.pageX - slider.offsetLeft; scrollLeft = slider.scrollLeft; });
    slider.addEventListener('mouseleave', () => { isDown = false; slider.classList.remove('cursor-grabbing'); });
    slider.addEventListener('mouseup', () => { isDown = false; slider.classList.remove('cursor-grabbing'); });
    slider.addEventListener('mousemove', e => { if (!isDown) return; e.preventDefault(); const x = e.pageX - slider.offsetLeft; slider.scrollLeft = scrollLeft - (x - startX) * 2; });
});

function openAddressModal() {
    const m = document.getElementById('address-modal'), i = m.querySelector('div');
    m.classList.remove('hidden'); m.classList.add('flex');
    setTimeout(() => { m.classList.remove('opacity-0'); i.classList.remove('md:scale-95'); }, 10);
    document.body.style.overflow = 'hidden';
}
function closeAddressModal() {
    const m = document.getElementById('address-modal'), i = m.querySelector('div');
    m.classList.add('opacity-0'); i.classList.add('md:scale-95');
    setTimeout(() => { m.classList.add('hidden'); m.classList.remove('flex'); document.body.style.overflow = ''; }, 300);
}
function openEditNameModal() {
    const m = document.getElementById('edit-name-modal'), i = m.querySelector('div');
    const n = document.querySelector('.user-name-display'); if (n) document.getElementById('name-input').value = n.innerText;
    m.classList.remove('hidden'); m.classList.add('flex');
    setTimeout(() => { m.classList.remove('opacity-0'); i.classList.remove('scale-95'); }, 10);
}
function closeEditNameModal() {
    const m = document.getElementById('edit-name-modal'), i = m.querySelector('div');
    m.classList.add('opacity-0'); i.classList.add('scale-95');
    setTimeout(() => { m.classList.add('hidden'); m.classList.remove('flex'); }, 300);
}
function openLogoutModal() {
    const m = document.getElementById('logout-modal'), i = m.querySelector('div');
    m.classList.remove('hidden'); m.classList.add('flex');
    setTimeout(() => { m.classList.remove('opacity-0'); i.classList.remove('scale-95'); }, 10);
}
function closeLogoutModal() {
    const m = document.getElementById('logout-modal'), i = m.querySelector('div');
    m.classList.add('opacity-0'); i.classList.add('scale-95');
    setTimeout(() => { m.classList.add('hidden'); m.classList.remove('flex'); }, 300);
}

function saveName() {
    const name = document.getElementById('name-input').value.trim();
    if (!name) { showToast('لطفا نام خود را وارد کنید', 'error'); return; }
    fetch('/profile', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify({ _method: 'PATCH', full_name: name })
    }).then(r => r.json()).then(d => {
        if (d.success) { document.querySelectorAll('.user-name-display').forEach(el => el.innerText = name); showToast(d.message || 'نام به‌روزرسانی شد', 'success'); closeEditNameModal(); }
        else { showToast(d.message || 'خطا در بروزرسانی', 'error'); }
    }).catch(() => showToast('خطای شبکه', 'error'));
}

function saveAddress() {
    const btn = document.getElementById('save-address-btn');
    btn.disabled = true; btn.textContent = 'در حال ذخیره...';
    const data = {
        receiver_name:   document.getElementById('addr-receiver-name').value,
        receiver_mobile: document.getElementById('addr-receiver-mobile').value,
        province:        'تهران',
        city:            'تهران',
        address_line:    document.getElementById('addr-address').value,
        plaque:          document.getElementById('addr-plaque').value,
        postal_code:     document.getElementById('addr-postal').value,
    };
    fetch('/addresses', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN, 'Accept': 'application/json' },
        body: JSON.stringify(data)
    }).then(r => r.json().then(d => ({ status: r.status, body: d }))).then(({ status, body }) => {
        if (status === 200 || status === 201 || body.success) {
            showToast(body.message || 'آدرس ذخیره شد', 'success');
            closeAddressModal();
            setTimeout(() => location.reload(), 800);
        } else {
            const err = body.errors ? Object.values(body.errors).flat().join(' ') : '';
            showToast(err || body.message || 'خطا در ذخیره آدرس', 'error');
            btn.disabled = false; btn.textContent = 'تایید و ذخیره آدرس';
        }
    }).catch(() => { showToast('خطای شبکه', 'error'); btn.disabled = false; btn.textContent = 'تایید و ذخیره آدرس'; });
}

function deleteAddress(id, btn) {
    if (!confirm('آیا از حذف این آدرس مطمئن هستید؟')) return;
    fetch('/addresses/' + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': CSRF_TOKEN } })
    .then(r => r.json()).then(d => {
        if (d.success) { showToast(d.message || 'آدرس حذف شد', 'success'); const card = btn.closest('[data-address-id]'); if (card) card.remove(); }
    }).catch(() => showToast('خطای شبکه', 'error'));
}

function setDefaultAddress(id) {
    fetch('/addresses/' + id + '/default', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF_TOKEN } })
    .then(r => r.json()).then(d => {
        if (d.success) { showToast(d.message || 'آدرس پیش‌فرض تغییر یافت', 'success'); setTimeout(() => location.reload(), 500); }
    }).catch(() => showToast('خطای شبکه', 'error'));
}


// ==================================================================
// WALLET — design/frontend prototype only.
// All state (balance, transactions) lives in localStorage on this
// browser only. Nothing here is sent to the server. Balance starts
// from the real value already rendered in the sidebar; top-ups and
// withdrawals only ever change the local mock copy. Replace this
// block with real API calls once the wallet backend exists.
// ==================================================================
(function () {
    const STORAGE_KEY = 'gp-wallet-mock-v1';
    const PRESETS = [50000, 100000, 200000, 500000, 1000000, 2000000];
    const MIN_AMOUNT = 50000;
    const INITIAL_BALANCE = 0;

    function toPersianDigits(str) {
        return String(str).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    }
    function formatToman(n) {
        return toPersianDigits(Number(n || 0).toLocaleString('en-US'));
    }
    function digitsOnly(v) {
        return (v || '').replace(/[^\d]/g, '');
    }
    function maskCard(raw) {
        const d = digitsOnly(raw).slice(0, 16);
        if (d.length < 16) return d;
        return d.slice(0, 4) + ' **** **** ' + d.slice(12);
    }
    function maskSheba(raw) {
        const d = digitsOnly(raw).slice(0, 24);
        if (d.length < 24) return 'IR' + d;
        return 'IR' + d.slice(0, 4) + ' **** **** **** **** ' + d.slice(20);
    }
    function formatCardInput(v) {
        return digitsOnly(v).slice(0, 16).replace(/(\d{4})(?=\d)/g, '$1 ');
    }
    function formatDate(ts) {
        const d = new Date(ts);
        const pad = n => String(n).padStart(2, '0');
        return toPersianDigits(`${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())}`);
    }

    function loadState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw) return JSON.parse(raw);
        } catch (e) {}
        return { balance: INITIAL_BALANCE, transactions: [], hidden: true };
    }
    function saveState() {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
    }

    let state = loadState();
    let walletTab = 'topup';
    let selectedPreset = PRESETS[1];
    let walletConfirmPending = null;

    function renderPresets() {
        const grid = document.getElementById('wallet-preset-grid');
        if (!grid) return;
        grid.innerHTML = '';
        PRESETS.forEach(n => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'h-12 rounded-lg border px-1 text-[11px] md:text-xs font-bold tabular-nums transition-colors ' +
                (selectedPreset === n && !document.getElementById('wallet-custom-amount').value
                    ? 'border-brandBlue bg-blue-50 text-brandBlue'
                    : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300');
            btn.textContent = formatToman(n) + ' تومان';
            btn.onclick = () => {
                selectedPreset = n;
                document.getElementById('wallet-custom-amount').value = '';
                hideWalletError();
                renderPresets();
            };
            grid.appendChild(btn);
        });
    }

    function currentAmount() {
        const custom = digitsOnly(document.getElementById('wallet-custom-amount').value);
        if (custom) return Number(custom);
        return selectedPreset || 0;
    }

    function hideWalletError() {
        const el = document.getElementById('wallet-form-error');
        el.classList.add('hidden');
        el.textContent = '';
    }
    function showWalletError(msg) {
        const el = document.getElementById('wallet-form-error');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    window.switchWalletTab = function (tab, btnEl) {
        walletTab = tab;
        hideWalletError();

        document.querySelectorAll('.wallet-tab-btn').forEach(b => {
            b.classList.remove('bg-brandBlue', 'text-white', 'shadow-sm');
            b.classList.add('text-gray-500');
        });
        const active = btnEl || document.querySelector(`.wallet-tab-btn[data-wallet-tab="${tab}"]`);
        if (active) {
            active.classList.add('bg-brandBlue', 'text-white', 'shadow-sm');
            active.classList.remove('text-gray-500');
        }

        const formPanel = document.getElementById('wallet-panel-form');
        const historyPanel = document.getElementById('wallet-panel-history');
        const withdrawFields = document.getElementById('wallet-withdraw-fields');

        if (tab === 'history') {
            formPanel.classList.add('hidden');
            historyPanel.classList.remove('hidden');
            historyPanel.classList.add('flex', 'flex-col');
            renderWalletHistory();
            return;
        }

        formPanel.classList.remove('hidden');
        historyPanel.classList.add('hidden');
        historyPanel.classList.remove('flex', 'flex-col');

        const title = document.getElementById('wallet-form-title');
        const hint = document.getElementById('wallet-form-hint');
        const eta = document.getElementById('wallet-form-eta');

        if (tab === 'topup') {
            title.textContent = 'افزایش موجودی کیف پول';
            hint.textContent = 'مبلغ مورد نظر برای افزایش موجودی را انتخاب کنید.';
            eta.textContent = 'واریز آنی به کیف پول';
            withdrawFields.classList.add('hidden');
        } else {
            title.textContent = 'برداشت وجه از کیف پول';
            hint.textContent = 'مبلغ، شماره کارت و شماره شبای مقصد را وارد کنید.';
            eta.textContent = 'برداشت وجه در ساعات کاری انجام می‌شود.';
            withdrawFields.classList.remove('hidden');
        }
        renderPresets();
    };

    window.toggleWalletBalance = function () {
        state.hidden = !state.hidden;
        saveState();
        renderBalance();
    };

    window.copyWalletId = function (walletId) {
        const btn = document.getElementById('wallet-copy-btn');
        const icon = btn.querySelector('i');
        const done = () => {
            icon.classList.remove('fa-regular', 'fa-copy');
            icon.classList.add('fa-solid', 'fa-check');
            showToast('شناسه کیف پول کپی شد', 'success');
            setTimeout(() => { icon.classList.remove('fa-solid', 'fa-check'); icon.classList.add('fa-regular', 'fa-copy'); }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(walletId).then(done).catch(() => showToast(walletId, 'info'));
        } else {
            showToast(walletId, 'info');
        }
    };

    window.openWalletConfirm = function () {
        const amount = currentAmount();
        hideWalletError();

        if (!amount || amount < MIN_AMOUNT) {
            showWalletError('حداقل مبلغ ' + formatToman(MIN_AMOUNT) + ' تومان است');
            return;
        }
        if (walletTab === 'withdraw') {
            if (amount > state.balance) {
                showWalletError('موجودی کیف پول کافی نیست');
                return;
            }
            const cardDigits = digitsOnly(document.getElementById('wallet-card-number').value);
            if (cardDigits.length !== 16) {
                showWalletError('شماره کارت باید ۱۶ رقم باشد');
                return;
            }
            const shebaDigits = digitsOnly(document.getElementById('wallet-sheba-number').value);
            if (shebaDigits.length !== 24) {
                showWalletError('شماره شبا باید ۲۴ رقم باشد');
                return;
            }
            walletConfirmPending = {
                kind: 'withdraw',
                amount,
                card: maskCard(document.getElementById('wallet-card-number').value),
                sheba: maskSheba(document.getElementById('wallet-sheba-number').value),
            };
        } else {
            walletConfirmPending = { kind: 'topup', amount };
        }

        const newBalance = walletConfirmPending.kind === 'topup'
            ? state.balance + amount
            : state.balance - amount;

        document.getElementById('wallet-confirm-title').textContent =
            walletConfirmPending.kind === 'topup' ? 'تایید افزایش موجودی' : 'تایید برداشت وجه';
        document.getElementById('wallet-confirm-amount-label').textContent =
            walletConfirmPending.kind === 'topup' ? 'مبلغ واریزی' : 'مبلغ برداشت';
        document.getElementById('wallet-confirm-amount').textContent = formatToman(amount) + ' تومان';
        document.getElementById('wallet-confirm-dest-label').textContent =
            walletConfirmPending.kind === 'topup' ? 'مقصد' : 'مقصد برداشت';
        document.getElementById('wallet-confirm-dest').textContent =
            walletConfirmPending.kind === 'topup' ? 'کیف پول درون‌برنامه' : 'کارت ' + walletConfirmPending.card;
        const shebaRow = document.getElementById('wallet-confirm-sheba-row');
        if (walletConfirmPending.kind === 'withdraw') {
            document.getElementById('wallet-confirm-sheba').textContent = walletConfirmPending.sheba;
            shebaRow.classList.remove('hidden');
        } else {
            shebaRow.classList.add('hidden');
        }
        document.getElementById('wallet-confirm-new-balance').textContent = formatToman(newBalance) + ' تومان';

        const m = document.getElementById('wallet-confirm-modal'), i = m.querySelector('div');
        m.classList.remove('hidden'); m.classList.add('flex');
        setTimeout(() => { m.classList.remove('opacity-0'); i.classList.remove('scale-95'); }, 10);
    };

    window.closeWalletConfirm = function () {
        const m = document.getElementById('wallet-confirm-modal'), i = m.querySelector('div');
        m.classList.add('opacity-0'); i.classList.add('scale-95');
        setTimeout(() => { m.classList.add('hidden'); m.classList.remove('flex'); }, 300);
        walletConfirmPending = null;
    };

    function finishWalletTx() {
        const { kind, amount, card, sheba } = walletConfirmPending;

        if (kind === 'topup') {
            state.balance += amount;
            state.transactions.unshift({ id: 'tx-' + Date.now(), type: 'topup', amount, createdAt: Date.now() });
            showToast('پرداخت با موفقیت انجام شد و موجودی کیف پول افزایش یافت', 'success');
        } else {
            if (amount > state.balance) { showToast('موجودی کیف پول کافی نیست', 'error'); closeWalletConfirm(); return; }
            state.balance -= amount;
            state.transactions.unshift({ id: 'tx-' + Date.now(), type: 'withdraw', amount, createdAt: Date.now(), destination: card, destinationSheba: sheba });
            showToast('درخواست برداشت شما ثبت شد. مبلغ در ساعات کاری توسط GamePek به حساب شما منتقل می‌شود.', 'success');
        }
        saveState();
        renderBalance();

        document.getElementById('wallet-custom-amount').value = '';
        document.getElementById('wallet-card-number').value = '';
        document.getElementById('wallet-sheba-number').value = '';
        selectedPreset = PRESETS[1];
        closeWalletConfirm();
        switchWalletTab('history');
    }

    // Top-up has no real gateway wired up yet: PaymentService/PaymentTransaction
    // are hard-coupled to Order (order_id is NOT NULL) and there is no Wallet
    // purpose branch. Until that backend work happens, top-up only simulates
    // the "redirecting to gateway" transition below; it never leaves this page.
    window.commitWalletTx = function () {
        if (!walletConfirmPending) return;
        const btn = document.getElementById('wallet-confirm-submit-btn');

        if (walletConfirmPending.kind === 'topup') {
            const originalLabel = btn.textContent;
            btn.disabled = true;
            btn.classList.add('opacity-70', 'cursor-not-allowed');
            btn.textContent = 'در حال انتقال به درگاه پرداخت...';
            setTimeout(() => {
                finishWalletTx();
                btn.disabled = false;
                btn.classList.remove('opacity-70', 'cursor-not-allowed');
                btn.textContent = originalLabel;
            }, 900);
        } else {
            finishWalletTx();
        }
    };

    function renderWalletHistory() {
        const list = document.getElementById('wallet-tx-list');
        const empty = document.getElementById('wallet-tx-empty');
        list.innerHTML = '';
        if (!state.transactions.length) {
            empty.classList.remove('hidden');
            empty.classList.add('flex');
            return;
        }
        empty.classList.add('hidden');
        empty.classList.remove('flex');
        state.transactions.forEach(tx => {
            const isIn = tx.type === 'topup';
            const li = document.createElement('li');
            li.className = 'flex items-center gap-3 px-4 md:px-5 py-3.5';
            li.innerHTML = `
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${isIn ? 'bg-blue-50 text-brandBlue' : 'bg-gray-50 text-flashRed'}">
                    <i class="fa-solid ${isIn ? 'fa-arrow-down-left' : 'fa-arrow-up-right'}"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-gray-800">${isIn ? 'افزایش موجودی' : 'برداشت وجه'}</p>
                    <p class="truncate text-xs text-gray-400 mt-0.5">${formatDate(tx.createdAt)}${tx.destination ? ' · کارت ' + tx.destination : ''}${tx.destinationSheba ? ' · شبا ' + tx.destinationSheba : ''}</p>
                </div>
                <p class="text-sm font-black tabular-nums shrink-0 ${isIn ? 'text-brandBlue' : 'text-flashRed'}">${isIn ? '+' : '−'}${formatToman(tx.amount)}</p>
            `;
            list.appendChild(li);
        });
    }

    function renderBalance() {
        const sidebarEl = document.getElementById('wallet-sidebar-balance');
        if (sidebarEl) sidebarEl.textContent = formatToman(state.balance);

        const amountEl = document.getElementById('wallet-balance-amount');
        const currencyEl = document.getElementById('wallet-balance-currency');
        const icon = document.querySelector('#wallet-hide-toggle i');
        if (!amountEl) return;
        if (state.hidden) {
            amountEl.textContent = '••••••';
            currencyEl.classList.add('hidden');
            if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
        } else {
            amountEl.textContent = formatToman(state.balance);
            currencyEl.classList.remove('hidden');
            if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
        }
    }

    function initWallet() {
        if (!document.getElementById('sec-wallet')) return;
        renderBalance();
        renderPresets();
        switchWalletTab('topup');
    }

    document.addEventListener('DOMContentLoaded', initWallet);
    if (document.readyState !== 'loading') initWallet();
})();

// Open a specific tab directly via ?tab=wallet (or #wallet), so the wallet
// and other sections can be linked to from outside this page. Falls back to
// the summary tab, which is the existing default.
(function () {
    const valid = ['summary', 'orders', 'wallet', 'addresses', 'messages', 'recent', 'account'];
    const requested = (new URLSearchParams(location.search).get('tab') || location.hash.replace('#', '')).toLowerCase();
    const tab = valid.includes(requested) ? requested : 'summary';
    switchProfileTab(tab, document.querySelector(`.sidebar-link[data-tab="${tab}"]`));
})();
</script>
@endpush
