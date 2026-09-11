@extends('admin.layouts.app')

@section('title', 'تراکنش #' . $transaction->id)
@section('page_badge', 'جزئیات پرداخت')
@section('breadcrumb')
    <a href="{{ route('admin.payments.index') }}" class="hover:text-brandBlue transition-colors text-sm">پرداخت‌ها</a>
    <i class="fa-solid fa-chevron-left text-[10px] mx-1"></i>
    <span class="text-gray-700 text-sm">#{{ $transaction->id }}</span>
@endsection

@section('content')

@php
$statusMeta = [
    'pending'   => ['label' => 'در انتظار', 'cls' => 'text-yellow-700 bg-yellow-50 border-yellow-200'],
    'success'   => ['label' => 'موفق',      'cls' => 'text-green-700 bg-green-50 border-green-200'],
    'failed'    => ['label' => 'ناموفق',    'cls' => 'text-red-700 bg-red-50 border-red-200'],
    'cancelled' => ['label' => 'لغوشده',   'cls' => 'text-gray-500 bg-gray-100 border-gray-200'],
    'refunded'  => ['label' => 'مسترد',    'cls' => 'text-orange-700 bg-orange-50 border-orange-200'],
];
$sm = $statusMeta[$transaction->status] ?? ['label' => $transaction->status, 'cls' => 'text-gray-500 bg-gray-100 border-gray-200'];
@endphp

<div class="flex flex-col xl:flex-row gap-6">

    {{-- Left column --}}
    <div class="flex-1 flex flex-col gap-5">

        {{-- Transaction details --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h2 class="font-bold text-gray-800 text-sm mb-5 flex items-center gap-2">
                <i class="fa-solid fa-credit-card text-brandBlue text-xs"></i> اطلاعات تراکنش
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <p class="text-[10px] text-gray-400 mb-1">وضعیت</p>
                    <span class="inline-flex items-center text-xs font-medium px-3 py-1 rounded-full border {{ $sm['cls'] }}">
                        {{ $sm['label'] }}
                    </span>
                </div>
                <div>
                    <p class="text-[10px] text-gray-400 mb-1">درگاه پرداخت</p>
                    <p class="font-mono font-bold text-sm uppercase text-gray-800">{{ $transaction->gateway }}</p>
                </div>
                <div>
                    <p class="text-[10px] text-gray-400 mb-1">مبلغ</p>
                    <p class="font-bold text-xl text-gray-800">{{ number_format($transaction->amount) }} <small class="text-gray-400 font-normal text-sm">تومان</small></p>
                </div>
                <div>
                    <p class="text-[10px] text-gray-400 mb-1">زمان ثبت</p>
                    <p class="text-sm text-gray-800" dir="ltr">{{ $transaction->created_at->format('Y/m/d H:i:s') }}</p>
                </div>
                @if($transaction->tracking_code)
                <div>
                    <p class="text-[10px] text-gray-400 mb-1">کد پیگیری</p>
                    <p class="font-mono font-bold text-sm text-brandBlue" dir="ltr">{{ $transaction->tracking_code }}</p>
                </div>
                @endif
                @if($transaction->authority)
                <div>
                    <p class="text-[10px] text-gray-400 mb-1">کد درگاه (Authority)</p>
                    <p class="font-mono text-xs text-gray-600 break-all" dir="ltr">{{ $transaction->authority }}</p>
                </div>
                @endif
                @if($transaction->paid_at)
                <div>
                    <p class="text-[10px] text-gray-400 mb-1">زمان پرداخت</p>
                    <p class="text-sm text-green-700 font-medium" dir="ltr">{{ $transaction->paid_at->format('Y/m/d H:i:s') }}</p>
                </div>
                @endif
            </div>
        </div>

        {{-- Order items --}}
        @if($transaction->order && $transaction->order->items->isNotEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h2 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
                <i class="fa-solid fa-box text-brandBlue text-xs"></i> اقلام سفارش
            </h2>
            <div class="flex flex-col gap-2">
                @foreach($transaction->order->items as $item)
                <div class="flex items-center gap-3 bg-gray-50 rounded-xl p-3">
                    <div class="w-10 h-10 bg-white rounded-lg border border-gray-100 flex items-center justify-center shrink-0">
                        @if($item->product_image_snapshot)
                        <img src="{{ media_url($item->product_image_snapshot) }}" alt="" class="w-full h-full object-contain rounded-lg">
                        @else
                        <i class="fa-solid fa-box text-gray-300 text-xs"></i>
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-800 truncate">{{ $item->product_title_snapshot }}</p>
                        <p class="text-[10px] text-gray-400">تعداد: {{ $item->quantity }}</p>
                    </div>
                    <p class="font-bold text-sm text-gray-800 shrink-0">{{ number_format($item->total_price) }}</p>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Raw gateway request/response is intentionally not rendered here (security policy) --}}
    </div>

    {{-- Right sidebar --}}
    <div class="w-full xl:w-72 shrink-0 flex flex-col gap-5">

        {{-- Related order --}}
        @if($transaction->order)
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-4">سفارش مرتبط</h3>
            <p class="font-mono font-bold text-sm text-brandBlue mb-1" dir="ltr">{{ $transaction->order->order_number }}</p>
            <p class="text-xs text-gray-500 mb-3">مجموع: {{ number_format($transaction->order->total) }} تومان</p>
            @can('view_orders')
            <a href="{{ route('admin.orders.show', $transaction->order) }}"
               class="block text-center text-xs text-brandBlue border border-brandBlue/30 rounded-xl py-1.5 hover:bg-brandBlue hover:text-white transition-all">
                مشاهده سفارش
            </a>
            @endcan
        </div>
        @endif

        {{-- Manual decision (only for eligible pending transactions) --}}
        @can('mark_payment_status')
        @if($transaction->status === 'pending')
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-1">تصمیم دستی</h3>
            <p class="text-[11px] text-gray-400 mb-4">در صورت تأیید دستی پرداخت (مثلاً واریز بانکی تأییدشده)، این تراکنش را تأیید یا رد کنید.</p>

            <form method="POST" action="{{ route('admin.payments.approve', $transaction) }}" class="mb-3"
                  onsubmit="return confirm('آیا از تأیید این پرداخت مطمئن هستید؟ این عملیات سفارش را به‌عنوان پرداخت‌شده ثبت می‌کند.')">
                @csrf
                <textarea aria-label="یادداشت تأیید (اختیاری)" name="reason" rows="2" maxlength="500" placeholder="یادداشت تأیید (اختیاری)"
                          class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs outline-none focus:border-brandBlue mb-2"></textarea>
                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors">
                    <i class="fa-solid fa-check ml-1"></i> تأیید پرداخت
                </button>
            </form>

            <form method="POST" action="{{ route('admin.payments.reject', $transaction) }}"
                  onsubmit="return confirm('آیا از رد این پرداخت مطمئن هستید؟')">
                @csrf
                <textarea aria-label="دلیل رد (الزامی)" name="reason" rows="2" maxlength="500" required placeholder="دلیل رد (الزامی)"
                          class="w-full bg-gray-50 border {{ $errors->has('reason') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-3 py-2 text-xs outline-none focus:border-brandBlue mb-2"></textarea>
                @error('reason') <p class="text-red-500 text-[11px] mb-2">{{ $message }}</p> @enderror
                <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-600 font-bold py-2.5 rounded-xl text-sm transition-colors">
                    <i class="fa-solid fa-xmark ml-1"></i> رد پرداخت
                </button>
            </form>
        </div>
        @endif
        @endcan

        {{-- Customer --}}
        @if($transaction->user)
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-3">کاربر</h3>
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-full bg-brandBlue/10 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-user text-brandBlue text-sm"></i>
                </div>
                <div>
                    <p class="font-medium text-gray-800 text-sm">{{ $transaction->user->full_name }}</p>
                    <p class="text-xs text-gray-400" dir="ltr">{{ $transaction->user->mobile }}</p>
                </div>
            </div>
            @can('view_users')
            <a href="{{ route('admin.users.show', $transaction->user) }}"
               class="block text-center text-xs text-brandBlue border border-brandBlue/30 rounded-xl py-1.5 hover:bg-brandBlue hover:text-white transition-all">
                مشاهده پروفایل
            </a>
            @endcan
        </div>
        @endif
    </div>
</div>

@endsection
