@extends('admin.layouts.app')

@section('title', 'جزئیات دستگاه')
@section('page_badge', 'ناوگان')
@section('breadcrumb') <a href="{{ route('admin.devices.index') }}" class="text-gray-500 text-sm hover:text-gray-700">دستگاه‌ها</a> <span class="text-gray-300 mx-1">/</span> <span class="text-gray-700 text-sm">جزئیات</span> @endsection

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

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-start justify-between gap-3 mb-5">
            <div>
                <h2 class="text-lg font-black text-gray-800">{{ $device->product?->title_fa ?? '—' }}</h2>
                <p class="text-xs text-gray-500 mt-1">شناسه دستگاه: <span class="font-mono" dir="ltr">{{ $device->id }}</span></p>
            </div>
            <span class="text-[11px] border rounded-full px-3 py-1 {{ $badge }}">{{ $device->state->label() }}</span>
        </div>

        <dl class="text-sm divide-y divide-gray-100">
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">شماره سریال</dt>
                <dd class="font-mono text-gray-800" dir="ltr">{{ $device->serial_number }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">مالکیت</dt>
                <dd class="font-medium text-gray-800">{{ $device->ownership->label() }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">مالک</dt>
                <dd class="font-medium text-gray-800">
                    @if ($device->owner)
                        <a href="{{ route('admin.owners.show', $device->owner) }}" class="text-brandBlue hover:underline">{{ $device->ownerLabel() }}</a>
                    @else
                        {{ $device->ownerLabel() }}
                    @endif
                </dd>
            </div>
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
            @if ($device->approvedBy)
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">تأییدکننده</dt>
                    <dd class="font-medium text-gray-800">{{ $device->approvedBy->full_name }}</dd>
                </div>
            @endif
            @if ($device->rejection_reason)
                <div class="py-2.5">
                    <dt class="text-gray-500 mb-1">دلیل رد</dt>
                    <dd class="text-red-700">{{ $device->rejection_reason }}</dd>
                </div>
            @endif
            @if ($device->disabled_reason)
                <div class="py-2.5">
                    <dt class="text-gray-500 mb-1">دلیل غیرفعال‌سازی</dt>
                    <dd class="text-gray-700">{{ $device->disabled_reason }}</dd>
                </div>
            @endif
            @if ($device->notes)
                <div class="py-2.5">
                    <dt class="text-gray-500 mb-1">توضیحات مالک</dt>
                    <dd class="text-gray-700 leading-6">{{ $device->notes }}</dd>
                </div>
            @endif
        </dl>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5 h-fit">
        <h3 class="text-sm font-bold text-gray-800 mb-3">بررسی کارشناسی</h3>

        <p class="text-[11px] text-gray-500 leading-6 mb-4">
            ثبت دستگاه به معنای تأیید آن نیست. فرایند کامل بررسی فنی و ویدیویی در فاز بعد اضافه می‌شود.
        </p>

        @if ($device->state === DeviceState::PendingReview)
            <form method="POST" action="{{ route('admin.devices.approve', $device) }}" class="mb-3">
                @csrf
                <button type="submit" data-confirm="این دستگاه تأیید و وارد ناوگان اجاره شود؟"
                        class="w-full bg-green-600 text-white rounded-xl px-4 py-2.5 text-sm font-bold hover:bg-green-700 transition-colors">
                    تأیید دستگاه
                </button>
            </form>

            <form method="POST" action="{{ route('admin.devices.reject', $device) }}">
                @csrf
                <input type="text" name="reason" required placeholder="دلیل رد"
                       class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue mb-2">
                @error('reason')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror
                <button type="submit" data-confirm="این دستگاه رد شود؟"
                        class="w-full border border-red-200 text-red-600 rounded-xl px-4 py-2.5 text-sm font-bold hover:bg-red-50 transition-colors">
                    رد دستگاه
                </button>
            </form>
        @else
            <p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3">
                این دستگاه در وضعیت «{{ $device->state->label() }}» است و اقدام بررسی جدیدی ندارد.
            </p>
        @endif
    </div>
</div>
@endsection
