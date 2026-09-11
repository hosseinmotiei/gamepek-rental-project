@extends('layouts.app')

@section('title', 'گیم‌پک اجاره | تحویل دستگاه')

@section('content')
@php
    use App\Enums\CustodyTransferState;

    $transfer = $operation->custodyTransfer;

    // The owner is party to two legs: the pickup from them and the return to
    // them. Only the wording differs; the confirmation means the same thing.
    $isReturnToOwner = $operation->type === App\Enums\RentalOperationType::OwnerReturn;
@endphp

<div class="max-w-2xl mx-auto px-4 py-8">
    <a href="{{ route('owner.operations.index') }}" class="text-xs text-gray-500 hover:text-gray-700 inline-block mb-4">
        <i class="fa-solid fa-chevron-right text-[10px] ml-1"></i> بازگشت
    </a>

    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 md:p-6">
        <h1 class="text-lg font-black text-gray-800">{{ $operation->type->label() }}</h1>
        <p class="text-xs text-gray-500 mt-1">شماره عملیات: <span class="font-mono" dir="ltr">{{ $operation->operation_number }}</span></p>

        {{-- Nothing about the customer appears here: not their name, not their
             contact details, not the payment. The owner needs the device, the
             dates and the handover state, and no more than that. --}}
        <dl class="text-sm divide-y divide-gray-100 mt-5">
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">دستگاه</dt>
                <dd class="text-gray-700 text-xs">{{ $operation->device?->product?->title_fa ?? '—' }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">سریال</dt>
                <dd class="text-gray-700 text-xs font-mono" dir="ltr">{{ $operation->deviceLabel() }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">بازه اجاره</dt>
                <dd class="text-gray-700 text-xs">
                    @if ($operation->reservation)
                        {{ \App\Support\Rental\Jalali::formatLong($operation->reservation->start_date->toDateString()) }}
                        تا
                        {{ \App\Support\Rental\Jalali::formatLong($operation->reservation->end_date->toDateString()) }}
                    @else — @endif
                </dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">وضعیت</dt>
                <dd class="text-gray-700 text-xs">{{ $operation->state->label() }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">زمان هماهنگ‌شده</dt>
                <dd class="text-gray-700 text-xs" dir="ltr">{{ $operation->scheduled_at?->format('Y-m-d H:i') ?? '—' }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">وضعیت تحویل</dt>
                <dd class="text-gray-700 text-xs">{{ $transfer?->state->label() ?? 'ثبت نشده' }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">اکنون در اختیار</dt>
                <dd class="text-gray-700 text-xs">{{ $operation->device?->currentCustody()->label() ?? '—' }}</dd>
            </div>
        </dl>

        <p class="text-[11px] text-gray-400 mt-5 leading-6">
            سپردن دستگاه به گیم‌پک مالکیت آن را منتقل نمی‌کند؛ دستگاه همچنان به نام شما ثبت است.
            تأیید شما صرفاً تأیید همین سابقه است و امضا یا رسید قانونی محسوب نمی‌شود.
        </p>

        @if ($transfer && $transfer->state === CustodyTransferState::Transferred)
            <form method="POST" action="{{ route('owner.operations.acknowledge', $operation) }}" class="mt-5">
                @csrf
                <button type="submit"
                        data-confirm="{{ $isReturnToOwner ? 'دریافت این دستگاه از گیم‌پک را تأیید می‌کنید؟' : 'تحویل این دستگاه به گیم‌پک را تأیید می‌کنید؟' }}"
                        class="w-full bg-brandBlue text-white rounded-xl px-4 py-3 text-sm font-bold">
                    {{ $isReturnToOwner ? 'تأیید دریافت دستگاه' : 'تأیید تحویل دستگاه' }}
                </button>
            </form>
        @endif
    </div>
</div>
@endsection
