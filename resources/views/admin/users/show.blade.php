@extends('admin.layouts.app')

@section('title', $user->full_name ?: $user->mobile)
@section('page_badge', 'پروفایل کاربر')
@section('breadcrumb')
    <a href="{{ route('admin.users.index') }}" class="hover:text-brandBlue transition-colors text-sm">کاربران</a>
    <i class="fa-solid fa-chevron-left text-[10px] mx-1"></i>
    <span class="text-gray-700 text-sm">{{ $user->full_name ?: $user->mobile }}</span>
@endsection

@section('content')

@php
$statusLabels = [
    'pending_payment' => ['label' => 'در انتظار پرداخت', 'cls' => 'text-yellow-700 bg-yellow-50'],
    'paid'            => ['label' => 'پرداخت شده',       'cls' => 'text-blue-700 bg-blue-50'],
    'processing'      => ['label' => 'در حال پردازش',    'cls' => 'text-indigo-700 bg-indigo-50'],
    'shipped'         => ['label' => 'ارسال شده',        'cls' => 'text-purple-700 bg-purple-50'],
    'delivered'       => ['label' => 'تحویل داده شده',   'cls' => 'text-green-700 bg-green-50'],
    'cancelled'       => ['label' => 'لغو شده',          'cls' => 'text-red-700 bg-red-50'],
    'refunded'        => ['label' => 'مسترد شده',        'cls' => 'text-orange-700 bg-orange-50'],
    'failed'          => ['label' => 'ناموفق',           'cls' => 'text-gray-500 bg-gray-100'],
];
@endphp

{{-- Action bar --}}
<div class="flex flex-wrap items-center gap-3 mb-6">
    @can('block_users')
    @if($user->id !== auth()->id())
    <form method="POST" action="{{ route('admin.users.toggle-block', $user) }}"
          onsubmit="return confirm('{{ $user->status === 'active' ? 'مسدود کردن' : 'فعال کردن' }} این کاربر؟')">
        @csrf @method('PATCH')
        <button type="submit"
                class="inline-flex items-center gap-2 text-sm font-bold px-4 py-2 rounded-xl transition-colors {{ $user->status === 'active' ? 'bg-red-50 text-red-600 border border-red-200 hover:bg-red-100' : 'bg-green-50 text-green-600 border border-green-200 hover:bg-green-100' }}">
            <i class="fa-solid {{ $user->status === 'active' ? 'fa-ban' : 'fa-circle-check' }} text-xs"></i>
            {{ $user->status === 'active' ? 'مسدود کردن' : 'فعال کردن کاربر' }}
        </button>
    </form>
    @endif
    @endcan

    @can('view_orders')
    <a href="{{ route('admin.orders.index', ['search' => $user->mobile]) }}"
       class="inline-flex items-center gap-2 text-sm text-gray-600 border border-gray-200 px-4 py-2 rounded-xl hover:border-brandBlue hover:text-brandBlue transition-all">
        <i class="fa-solid fa-bag-shopping text-xs"></i> مشاهده سفارش‌ها
    </a>
    @endcan
</div>

<div class="flex flex-col xl:flex-row gap-6">

    {{-- Left column --}}
    <div class="flex-1 flex flex-col gap-5">

        {{-- Recent orders --}}
        @can('view_user_orders')
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h2 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
                <i class="fa-solid fa-bag-shopping text-brandBlue text-xs"></i>
                سفارش‌های اخیر
                @if($orders->isNotEmpty())
                <span class="mr-auto text-[10px] text-gray-400">{{ $orders->count() }} سفارش آخر</span>
                @endif
            </h2>

            @if($orders->isEmpty())
            <div class="py-8 text-center text-gray-400">
                <i class="fa-solid fa-bag-shopping text-2xl mb-2"></i>
                <p class="text-xs">هیچ سفارشی ثبت نشده.</p>
            </div>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-right">
                            <th class="pb-2 font-semibold text-gray-500 text-xs">شماره سفارش</th>
                            <th class="pb-2 font-semibold text-gray-500 text-xs">مبلغ</th>
                            <th class="pb-2 font-semibold text-gray-500 text-xs">وضعیت</th>
                            <th class="pb-2 font-semibold text-gray-500 text-xs hidden md:table-cell">تاریخ</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($orders as $order)
                        @php $om = $statusLabels[$order->status] ?? ['label' => $order->status, 'cls' => 'text-gray-500 bg-gray-100']; @endphp
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="py-3 pl-3">
                                <span class="font-mono text-xs font-bold text-brandBlue" dir="ltr">{{ $order->order_number }}</span>
                            </td>
                            <td class="py-3">
                                <span class="font-bold text-gray-800">{{ number_format($order->total) }}</span>
                                <small class="text-gray-400 text-[10px]"> ت</small>
                            </td>
                            <td class="py-3">
                                <span class="inline-flex items-center text-[10px] font-medium px-2 py-0.5 rounded-full {{ $om['cls'] }}">
                                    {{ $om['label'] }}
                                </span>
                            </td>
                            <td class="py-3 hidden md:table-cell">
                                <span class="text-xs text-gray-400">{{ $order->created_at->format('Y/m/d') }}</span>
                            </td>
                            <td class="py-3 text-left">
                                <a href="{{ route('admin.orders.show', $order) }}"
                                   class="text-[10px] text-brandBlue hover:underline">مشاهده</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
        @endcan

        {{-- Addresses --}}
        @if($user->addresses->isNotEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h2 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
                <i class="fa-solid fa-location-dot text-brandBlue text-xs"></i>
                آدرس‌ها
                <span class="mr-auto text-[10px] text-gray-400">{{ $user->addresses->count() }} آدرس</span>
            </h2>
            <div class="flex flex-col gap-3">
                @foreach($user->addresses as $address)
                <div class="border border-gray-100 rounded-xl p-4 {{ $address->is_default ? 'border-brandBlue/30 bg-blue-50/30' : '' }}">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <div>
                            <p class="font-medium text-gray-800 text-sm">{{ $address->receiver_name }}</p>
                            <p class="text-xs text-gray-500 font-mono" dir="ltr">{{ $address->receiver_mobile }}</p>
                        </div>
                        @if($address->is_default)
                        <span class="text-[10px] font-medium text-brandBlue bg-brandLightBlue px-2 py-0.5 rounded-full shrink-0">پیش‌فرض</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-600 leading-relaxed">{{ $address->full_address }}</p>
                    @if($address->postal_code)
                    <p class="text-[10px] text-gray-400 mt-1 font-mono" dir="ltr">کد پستی: {{ $address->postal_code }}</p>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- Right sidebar --}}
    <div class="w-full xl:w-72 shrink-0 flex flex-col gap-5">

        {{-- User identity --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <div class="flex flex-col items-center text-center mb-4">
                <div class="w-16 h-16 rounded-full bg-brandBlue/10 flex items-center justify-center mb-3 shrink-0">
                    @if($user->avatar)
                    <img src="{{ media_url($user->avatar) }}" alt="{{ $user->full_name }}"
                         class="w-full h-full object-cover rounded-full">
                    @else
                    <i class="fa-solid fa-user text-brandBlue text-2xl"></i>
                    @endif
                </div>
                <h2 class="font-bold text-gray-800 text-base">{{ $user->full_name ?: '(بدون نام)' }}</h2>
                <p class="text-sm text-gray-500 font-mono mt-0.5" dir="ltr">{{ $user->mobile }}</p>
                <div class="mt-2">
                    @if($user->status === 'active')
                    <span class="inline-flex items-center gap-1 text-[10px] font-medium text-green-600 bg-green-50 px-3 py-1 rounded-full">
                        <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span> فعال
                    </span>
                    @else
                    <span class="inline-flex items-center gap-1 text-[10px] font-medium text-red-600 bg-red-50 px-3 py-1 rounded-full">
                        <span class="w-1.5 h-1.5 bg-red-500 rounded-full"></span> مسدود
                    </span>
                    @endif
                </div>
            </div>
            <div class="flex flex-col gap-2 text-sm border-t border-gray-100 pt-4">
                @if($user->email)
                <div class="flex justify-between gap-2">
                    <span class="text-gray-400 shrink-0">ایمیل</span>
                    <span class="text-gray-700 text-xs truncate" dir="ltr">{{ $user->email }}</span>
                </div>
                @endif
                <div class="flex justify-between">
                    <span class="text-gray-400">تاریخ عضویت</span>
                    <span class="text-gray-700 text-xs">{{ $user->created_at->format('Y/m/d') }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">آخرین ورود</span>
                    <span class="text-gray-700 text-xs">
                        {{ $user->last_login_at ? $user->last_login_at->format('Y/m/d H:i') : '—' }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Stats --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-4">آمار کاربر</h3>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-xl p-3 text-center">
                    <p class="text-xl font-black text-brandBlue">{{ $user->orders_count }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">سفارش</p>
                </div>
                <div class="bg-green-50 rounded-xl p-3 text-center">
                    <p class="text-sm font-black text-green-600">{{ number_format($totalSpent) }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">ت خرید</p>
                </div>
                <div class="bg-yellow-50 rounded-xl p-3 text-center">
                    <p class="text-xl font-black text-yellow-600">{{ $user->wishlists_count }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">علاقه‌مندی</p>
                </div>
                <div class="bg-purple-50 rounded-xl p-3 text-center">
                    <p class="text-xl font-black text-purple-600">{{ $user->reviews_count }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">نظر</p>
                </div>
            </div>
        </div>

        {{-- Roles --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-3">دسترسی ادمین</h3>

            @if($user->roles->isNotEmpty())
            <div class="flex flex-wrap gap-1.5 mb-3">
                @foreach($user->roles as $role)
                <span class="text-[10px] font-medium bg-brandLightBlue text-brandBlue px-2.5 py-1 rounded-full">{{ $role->name }}</span>
                @endforeach
            </div>
            @else
            <p class="text-xs text-gray-400 mb-3">این کاربر دسترسی ادمین ندارد.</p>
            @endif

            @if(auth()->user()->hasRole('super_admin'))
                @if($user->id !== auth()->id())
                <form method="POST" action="{{ route('admin.users.update-role', $user) }}" class="flex items-center gap-2"
                      onsubmit="return confirm('نقش این کاربر تغییر کند؟')">
                    @csrf @method('PATCH')
                    <select name="role" class="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-brandBlue">
                        <option value="">بدون دسترسی ادمین</option>
                        @foreach($availableRoles as $role)
                        <option value="{{ $role->name }}" {{ $user->hasRole($role->name) ? 'selected' : '' }}>{{ $role->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="bg-brandBlue text-white text-xs font-bold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shrink-0">ثبت</button>
                </form>
                @else
                <p class="text-[10px] text-gray-400">نمی‌توانید نقش خودتان را تغییر دهید.</p>
                @endif
            @endif
        </div>

        {{-- Admin login credentials --}}
        @if(auth()->user()->hasRole('super_admin'))
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-1">ورود به پنل ادمین</h3>
            <p class="text-[11px] text-gray-400 mb-3">ورود پنل ادمین با ایمیل و رمز عبور انجام می‌شود، نه موبایل. برای این‌که این کاربر بتواند وارد پنل شود، ابتدا یک ایمیل و رمز عبور برایش ثبت کنید.</p>

            <form method="POST" action="{{ route('admin.users.update-credentials', $user) }}" class="flex flex-col gap-2.5"
                  onsubmit="return confirm('ایمیل و رمز عبور جدید برای این کاربر ثبت شود؟')">
                @csrf @method('PATCH')

                <div>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" placeholder="ایمیل (مثال: name@gmail.com)" dir="ltr"
                           class="w-full border {{ $errors->has('email') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-brandBlue">
                    @error('email')<p class="text-red-500 text-[10px] mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <input type="password" name="password" placeholder="رمز عبور جدید (حداقل ۸ کاراکتر)" dir="ltr"
                           class="w-full border {{ $errors->has('password') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-brandBlue">
                    @error('password')<p class="text-red-500 text-[10px] mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <input type="password" name="password_confirmation" placeholder="تکرار رمز عبور" dir="ltr"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-brandBlue">
                </div>

                <button type="submit" class="bg-brandBlue text-white text-xs font-bold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors">ثبت ایمیل و رمز عبور</button>
            </form>
        </div>
        @endif

        {{-- Addresses count --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-3">سایر اطلاعات</h3>
            <div class="flex flex-col gap-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">آدرس‌ها</span>
                    <span class="font-bold text-gray-800">{{ $user->addresses->count() }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">سوالات</span>
                    <span class="font-bold text-gray-800">{{ $user->questions_count }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

@can('view_users')
<div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm mt-6">
    <h3 class="font-bold text-gray-800 text-sm mb-4">فعالیت‌های اخیر کاربر</h3>
    @if($activityLogs->isEmpty())
    <p class="text-sm text-gray-400">فعالیتی ثبت نشده است.</p>
    @else
    <div class="flex flex-col divide-y divide-gray-50">
        @foreach($activityLogs as $log)
        <div class="py-2.5 flex items-center justify-between gap-4 text-sm">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="w-2 h-2 rounded-full bg-brandBlue shrink-0"></span>
                <span class="text-gray-700 truncate">{{ $log->description ?: $log->action }}</span>
            </div>
            <span class="text-xs text-gray-400 shrink-0">{{ $log->created_at->diffForHumans() }}</span>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endcan

@endsection
