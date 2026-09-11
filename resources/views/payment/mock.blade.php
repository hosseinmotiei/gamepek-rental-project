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
            <div class="text-center pb-1">
                <div class="w-14 h-14 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fa-solid fa-lock text-brandBlue text-xl"></i>
                </div>
                <p class="text-xs md:text-sm text-gray-400">مبلغ قابل پرداخت</p>
                <div class="text-2xl md:text-3xl font-black text-gray-900 mt-1">
                    {{ number_format($transaction->amount) }}
                    <span class="text-sm font-normal text-gray-500">تومان</span>
                </div>
            </div>

            <div class="flex items-center justify-between border-t border-gray-50 pt-4">
                <span class="text-xs md:text-sm text-gray-500">شماره سفارش</span>
                <span class="text-sm md:text-base font-bold text-gray-800" dir="ltr">{{ $transaction->order?->order_number ?? '-' }}</span>
            </div>

            <div class="flex items-center justify-between">
                <span class="text-xs md:text-sm text-gray-500">شناسه تراکنش</span>
                <span class="text-[11px] font-mono text-gray-600 break-all" dir="ltr">{{ $authority }}</span>
            </div>

            <div class="grid grid-cols-2 gap-3 pt-2">
                <form method="POST" action="{{ route('payment.mock.confirm.post', ['authority' => $authority]) }}" data-loading-label="در حال ثبت…">
                    @csrf
                    <input type="hidden" name="outcome" value="pay">
                    <button type="submit"
                            class="w-full inline-flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm rounded-xl py-3 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300 disabled:opacity-60 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-check"></i> پرداخت موفق
                    </button>
                </form>

                <form method="POST" action="{{ route('payment.mock.confirm.post', ['authority' => $authority]) }}" data-loading-label="در حال ثبت…">
                    @csrf
                    <input type="hidden" name="outcome" value="cancel">
                    <button type="submit"
                            class="w-full inline-flex items-center justify-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold text-sm rounded-xl py-3 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 disabled:opacity-60 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-xmark"></i> انصراف
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('main form[data-loading-label]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + form.dataset.loadingLabel;
        });
    });
</script>
@endpush
