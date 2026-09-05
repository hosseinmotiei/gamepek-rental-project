@extends('layouts.app')

@section('title', 'گیم‌پک | درگاه آزمایشی پرداخت')

@section('content')
{{--
    Development-only stand-in for the bank's own payment page. Never reachable
    outside local/testing -- MockPaymentController aborts with 404 elsewhere.

    Its whole reason for existing: the outcome of a payment must be decided on
    the server, not read out of a callback query string the browser controls.
--}}
<main class="min-h-[70vh] flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">

        <div class="bg-amber-50 border-b border-amber-200 px-5 py-3 flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation text-amber-500"></i>
            <span class="text-xs md:text-sm font-bold text-amber-700">درگاه آزمایشی — فقط محیط توسعه</span>
        </div>

        <div class="p-5 md:p-6 space-y-5">
            <div class="flex items-center justify-between">
                <span class="text-xs md:text-sm text-gray-500">شماره سفارش</span>
                <span class="text-sm md:text-base font-bold text-gray-800" dir="ltr">{{ $transaction->order?->order_number ?? '-' }}</span>
            </div>

            <div class="flex items-center justify-between">
                <span class="text-xs md:text-sm text-gray-500">مبلغ قابل پرداخت</span>
                <span class="text-base md:text-lg font-black text-brandBlue">
                    {{ number_format($transaction->amount) }} <span class="text-xs font-normal text-gray-500">تومان</span>
                </span>
            </div>

            <div class="flex items-center justify-between">
                <span class="text-xs md:text-sm text-gray-500">شناسه تراکنش</span>
                <span class="text-[11px] font-mono text-gray-600 break-all" dir="ltr">{{ $authority }}</span>
            </div>

            <div class="grid grid-cols-2 gap-3 pt-2">
                <form method="POST" action="{{ route('payment.mock.confirm.post', ['authority' => $authority]) }}">
                    @csrf
                    <input type="hidden" name="outcome" value="pay">
                    <button type="submit"
                            class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm rounded-xl py-3 transition">
                        پرداخت موفق
                    </button>
                </form>

                <form method="POST" action="{{ route('payment.mock.confirm.post', ['authority' => $authority]) }}">
                    @csrf
                    <input type="hidden" name="outcome" value="cancel">
                    <button type="submit"
                            class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold text-sm rounded-xl py-3 transition">
                        انصراف
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>
@endsection
