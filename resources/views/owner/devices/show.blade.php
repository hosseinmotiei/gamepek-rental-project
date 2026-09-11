@extends('layouts.app')

@section('title', 'گیم‌پک اجاره | جزئیات دستگاه')

@section('content')
@php
    use App\Enums\DeviceState;

    $badge = match ($device->state) {
        DeviceState::Approved => 'bg-green-50 text-green-700 border-green-200',
        DeviceState::PendingReview => 'bg-amber-50 text-amber-700 border-amber-200',
        DeviceState::Rejected => 'bg-red-50 text-red-700 border-red-200',
        DeviceState::Disabled => 'bg-gray-100 text-gray-600 border-gray-200',
        DeviceState::Draft => 'bg-gray-50 text-gray-500 border-gray-200',
    };
@endphp

<div class="max-w-2xl mx-auto px-4 py-8">
    <a href="{{ route('owner.dashboard') }}" class="text-xs text-gray-500 hover:text-gray-700 inline-block mb-4">
        <i class="fa-solid fa-chevron-right text-[10px] ml-1"></i> بازگشت به دستگاه‌های من
    </a>

    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 md:p-6">
        <div class="flex items-start justify-between gap-3 mb-5">
            <div class="min-w-0">
                <h1 class="text-lg md:text-xl font-black text-gray-800">{{ $device->product?->title_fa ?? '—' }}</h1>
                <p class="text-[11px] text-gray-500 mt-1 font-mono" dir="ltr">{{ $device->maskedSerial() }}</p>
            </div>
            <span class="shrink-0 text-[11px] border rounded-full px-3 py-1 whitespace-nowrap {{ $badge }}">{{ $device->state->label() }}</span>
        </div>

        <dl class="text-sm divide-y divide-gray-100">
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">وضعیت بررسی</dt>
                <dd class="font-medium text-gray-800">{{ $device->verification_state->label() }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">وضعیت ظاهری</dt>
                <dd class="font-medium text-gray-800">{{ $device->condition ?: '—' }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">تاریخ ثبت</dt>
                <dd class="font-medium text-gray-800">
                    {{ $device->registered_at ? \App\Support\Rental\Jalali::formatLong($device->registered_at->toDateString()) : '—' }}
                </dd>
            </div>
            @if ($device->state === DeviceState::Rejected && $device->rejection_reason)
                <div class="py-2.5">
                    <dt class="text-gray-500 mb-1">دلیل رد</dt>
                    <dd class="text-red-700">{{ $device->rejection_reason }}</dd>
                </div>
            @endif
        </dl>

        @if ($device->state === DeviceState::PendingReview)
            <p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 mt-4 leading-6">
                دستگاه شما در نوبت بررسی است. پس از بررسی کارشناسی، نتیجه در همین صفحه نمایش داده می‌شود.
            </p>
        @endif

        @can('disable', $device)
            <form method="POST" action="{{ route('owner.devices.disable', $device) }}" class="mt-5 pt-5 border-t border-gray-100">
                @csrf
                <label for="reason" class="block text-sm font-medium text-gray-700 mb-1.5">غیرفعال‌سازی دستگاه</label>
                <input type="text" id="reason" name="reason" placeholder="دلیل (اختیاری)"
                       class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue mb-3">
                <button type="submit"
                        data-confirm="آیا از غیرفعال‌سازی این دستگاه مطمئن هستید؟"
                        class="text-sm text-red-600 border border-red-200 rounded-xl px-4 py-2.5 hover:bg-red-50 transition-colors">
                    غیرفعال‌سازی
                </button>
            </form>
        @endcan
    </div>
</div>
@endsection
