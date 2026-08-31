@extends('admin.layouts.app')

@section('title', 'تنظیمات سایت')
@section('page_badge', 'تنظیمات')
@section('breadcrumb') <span class="text-gray-700 text-sm">تنظیمات</span> @endsection

@section('content')

<div class="flex flex-wrap items-start justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">تنظیمات سایت</h1>
        <p class="text-xs text-gray-500 mt-0.5">مدیریت و شخصی‌سازی محتوا و تنظیمات گیم‌پک</p>
    </div>
    <form method="POST" action="{{ route('admin.settings.clear-cache') }}">
        @csrf
        <button type="submit"
                onclick="return confirm('کش تنظیمات پاک شود؟')"
                class="inline-flex items-center gap-2 text-xs text-gray-600 border border-gray-200 px-3 py-2 rounded-xl hover:border-orange-400 hover:text-orange-600 transition-all">
            <i class="fa-solid fa-rotate-right text-[10px]"></i> پاک کردن کش تنظیمات
        </button>
    </form>
</div>

@php
$groupMeta = [
    'general'       => ['title' => 'تنظیمات عمومی',     'description' => 'نام سایت، اطلاعات تماس و شبکه‌های اجتماعی',  'icon' => 'fa-store',                    'color' => 'text-blue-500',   'bg' => 'bg-blue-50'],
    'theme'         => ['title' => 'تنظیمات ظاهری',     'description' => 'رنگ‌های برند، گردی کارت‌ها و تصاویر پیش‌فرض', 'icon' => 'fa-palette',                  'color' => 'text-purple-500', 'bg' => 'bg-purple-50'],
    'header'        => ['title' => 'تنظیمات هدر',       'description' => 'متن‌های نوار بالا، جستجو و دکمه‌های هدر',    'icon' => 'fa-heading',                  'color' => 'text-indigo-500', 'bg' => 'bg-indigo-50'],
    'footer'        => ['title' => 'تنظیمات فوتر',      'description' => 'فوتر، نمادهای اعتماد و کپی‌رایت',           'icon' => 'fa-shoe-prints',              'color' => 'text-teal-500',   'bg' => 'bg-teal-50'],
    'seo'           => ['title' => 'تنظیمات SEO',       'description' => 'عنوان متا، توضیحات و تنظیمات ربات‌های جستجو','icon' => 'fa-magnifying-glass-chart',   'color' => 'text-green-500',  'bg' => 'bg-green-50'],
    'notifications' => ['title' => 'متن‌های اعلان',     'description' => 'قالب پیام‌های SMS و اعلان‌های سیستمی',       'icon' => 'fa-bell',                     'color' => 'text-yellow-500', 'bg' => 'bg-yellow-50'],
    'checkout'      => ['title' => 'متن‌های خرید',      'description' => 'پیام‌های سبد، پرداخت و تکمیل سفارش',        'icon' => 'fa-cart-shopping',            'color' => 'text-orange-500', 'bg' => 'bg-orange-50'],
    'auth'          => ['title' => 'متن‌های ورود',      'description' => 'متن‌های صفحه ورود، OTP و ثبت‌نام',          'icon' => 'fa-right-to-bracket',         'color' => 'text-rose-500',   'bg' => 'bg-rose-50'],
    'profile'         => ['title' => 'متن‌های پروفایل',  'description' => 'عنوان‌ها و پیام‌های صفحه حساب کاربری',       'icon' => 'fa-user-circle',              'color' => 'text-slate-500',  'bg' => 'bg-slate-50'],
    'product_display' => ['title' => 'نمایش محصولات',    'description' => 'متن‌های کارت محصول، دکمه خرید و وضعیت',      'icon' => 'fa-tag',                      'color' => 'text-pink-500',   'bg' => 'bg-pink-50'],
];
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    @foreach($groups as $group)
    @if($group['access'])
    <a href="{{ route('admin.settings.show', $group['key']) }}"
       class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm hover:border-brandBlue hover:shadow-md transition-all group">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-xl {{ $groupMeta[$group['key']]['bg'] }} flex items-center justify-center shrink-0">
                <i class="fa-solid {{ $groupMeta[$group['key']]['icon'] }} {{ $groupMeta[$group['key']]['color'] }} text-sm"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-bold text-gray-800 text-sm group-hover:text-brandBlue transition-colors">{{ $group['title'] }}</p>
                <p class="text-xs text-gray-400 mt-0.5 leading-relaxed">{{ $group['description'] }}</p>
            </div>
            <i class="fa-solid fa-chevron-left text-gray-300 text-xs shrink-0 mt-0.5 group-hover:text-brandBlue transition-colors"></i>
        </div>
    </a>
    @else
    <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm opacity-50 cursor-not-allowed">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-xl {{ $groupMeta[$group['key']]['bg'] }} flex items-center justify-center shrink-0">
                <i class="fa-solid {{ $groupMeta[$group['key']]['icon'] }} {{ $groupMeta[$group['key']]['color'] }} text-sm opacity-50"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-bold text-gray-600 text-sm">{{ $group['title'] }}</p>
                <p class="text-xs text-gray-400 mt-0.5 leading-relaxed">{{ $group['description'] }}</p>
            </div>
            <i class="fa-solid fa-lock text-gray-300 text-xs shrink-0 mt-0.5"></i>
        </div>
    </div>
    @endif
    @endforeach
</div>

<div class="mt-6 bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-700">
    <p class="font-bold mb-1"><i class="fa-solid fa-circle-info ml-1"></i>راهنما</p>
    <p class="leading-relaxed">تغییر تنظیمات به صورت آنی در سایت اعمال می‌شود. پس از ذخیره، کش تنظیمات به صورت خودکار پاک می‌شود. برای اعمال تغییرات رنگ‌ها در فرانت‌اند، نیاز به به‌روزرسانی Tailwind config است که در فازهای بعدی انجام خواهد شد.</p>
</div>

@endsection
