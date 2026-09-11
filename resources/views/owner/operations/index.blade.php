@extends('layouts.app')

@section('title', 'گیم‌پک اجاره | تحویل دستگاه‌های من')

@section('content')
@php
    use App\Enums\RentalOperationState;

    $badge = fn (RentalOperationState $s) => match ($s) {
        RentalOperationState::Completed => 'bg-green-50 text-green-700 border-green-200',
        RentalOperationState::InProgress => 'bg-blue-50 text-blue-700 border-blue-200',
        RentalOperationState::Scheduled => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        RentalOperationState::AwaitingDeviceAllocation => 'bg-amber-50 text-amber-700 border-amber-200',
        RentalOperationState::Failed => 'bg-red-50 text-red-700 border-red-200',
        RentalOperationState::NotRequired => 'bg-gray-100 text-gray-600 border-gray-200',
        RentalOperationState::Pending => 'bg-gray-50 text-gray-500 border-gray-200',
    };
@endphp

<div class="max-w-2xl mx-auto px-4 py-8">
    <a href="{{ route('owner.dashboard') }}" class="text-xs text-gray-500 hover:text-gray-700 inline-block mb-4">
        <i class="fa-solid fa-chevron-right text-[10px] ml-1"></i> بازگشت به دستگاه‌های من
    </a>

    <h1 class="text-lg font-black text-gray-800 mb-1">تحویل دستگاه‌های من</h1>
    <p class="text-xs text-gray-500 mb-6 leading-6">
        وقتی دستگاه شما برای یک اجاره انتخاب شود، اینجا از شما درخواست تحویل می‌شود.
        سپردن دستگاه به گیم‌پک مالکیت آن را تغییر نمی‌دهد.
    </p>

    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('error') }}</div>
    @endif

    <div class="space-y-3">
        @forelse ($operations as $operation)
            <a href="{{ route('owner.operations.show', $operation) }}"
               class="block bg-white rounded-2xl border border-gray-200 hover:border-gray-300 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-bold text-gray-800">{{ $operation->device?->product?->title_fa ?? '—' }}</p>
                        <p class="text-xs text-gray-500 mt-1 {{ $operation->hasDevice() ? 'font-mono' : '' }}" dir="{{ $operation->hasDevice() ? 'ltr' : 'rtl' }}">{{ $operation->deviceLabel() }}</p>
                    </div>
                    <span class="text-[11px] border rounded-full px-3 py-1 whitespace-nowrap {{ $badge($operation->state) }}">{{ $operation->state->label() }}</span>
                </div>
            </a>
        @empty
            <div class="bg-white rounded-2xl border border-gray-200 p-10 text-center text-sm text-gray-400">
                درخواست تحویلی برای دستگاه‌های شما ثبت نشده است.
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $operations->links() }}</div>
</div>
@endsection
