@extends('layouts.app')

@section('title', 'گیم‌پک اجاره | دستگاه‌های من')

@section('content')
@php
    use App\Enums\DeviceState;

    $badge = fn (DeviceState $s) => match ($s) {
        DeviceState::Approved => 'bg-green-50 text-green-700 border-green-200',
        DeviceState::PendingReview => 'bg-amber-50 text-amber-700 border-amber-200',
        DeviceState::Rejected => 'bg-red-50 text-red-700 border-red-200',
        DeviceState::Disabled => 'bg-gray-100 text-gray-600 border-gray-200',
        DeviceState::Draft => 'bg-gray-50 text-gray-500 border-gray-200',
    };
@endphp

<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-xl md:text-2xl font-black text-gray-800">دستگاه‌های من</h1>
            <p class="text-xs md:text-sm text-gray-500 mt-1">
                {{ $owner->displayName() }} —
                <span class="font-medium">{{ $owner->state->label() }}</span>
            </p>
        </div>
        <a href="{{ route('owner.devices.create') }}"
           class="bg-brandBlue text-white rounded-xl px-4 py-2.5 text-sm font-bold hover:bg-blue-700 transition-colors text-center">
            <i class="fa-solid fa-plus ml-1"></i> ثبت دستگاه جدید
        </a>
    </div>

    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('error') }}</div>
    @endif

    @if ($devices->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-10 text-center">
            <i class="fa-solid fa-gamepad text-3xl text-gray-300 mb-3"></i>
            <p class="text-sm text-gray-500 mb-4">هنوز دستگاهی ثبت نکرده‌اید.</p>
            <a href="{{ route('owner.devices.create') }}" class="text-brandBlue text-sm font-bold hover:underline">ثبت اولین دستگاه</a>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100">
            @foreach ($devices as $device)
                <a href="{{ route('owner.devices.show', $device) }}"
                   class="flex items-center justify-between gap-3 px-4 py-4 hover:bg-gray-50 transition-colors">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-gray-800 truncate">{{ $device->product?->title_fa ?? '—' }}</p>
                        <p class="text-[11px] text-gray-500 mt-0.5 font-mono" dir="ltr">{{ $device->maskedSerial() }}</p>
                    </div>
                    <span class="shrink-0 text-[11px] border rounded-full px-3 py-1 {{ $badge($device->state) }}">
                        {{ $device->state->label() }}
                    </span>
                </a>
            @endforeach
        </div>

        <div class="mt-5">{{ $devices->links() }}</div>
    @endif
</div>
@endsection
