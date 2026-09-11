@extends('layouts.app')

@section('title', 'گیم‌پک | قرارداد اجاره')

@php
    use App\Enums\ContractState;

    $badgeTone = match ($contract->state) {
        ContractState::Signed => 'bg-emerald-50 text-emerald-700',
        ContractState::Void => 'bg-red-50 text-red-700',
        default => 'bg-blue-50 text-brandBlue',
    };
@endphp

@section('content')
<main class="max-w-3xl mx-auto px-4 py-6 md:py-10" dir="rtl">

    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
        <h1 class="text-lg md:text-2xl font-black text-gray-800 flex items-center gap-2">
            <i class="fa-solid fa-file-signature text-gray-400"></i>
            قرارداد اجاره
        </h1>
        <span class="text-[11px] md:text-xs font-bold px-3 py-1.5 rounded-full {{ $badgeTone }}">
            {{ $contract->state->label() }}
        </span>
    </div>

    <div class="text-[11px] text-gray-400 mb-4" dir="ltr">
        {{ $contract->number }} — v{{ $contract->template_version }}
    </div>

    @if (session('success'))
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs md:text-sm px-4 py-3">
            <i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session('dev_otp_message'))
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-blue-50 border border-blue-200 text-brandBlue text-xs md:text-sm px-4 py-3">
            <i class="fa-solid fa-circle-info mt-0.5 shrink-0"></i>
            <span>{{ session('dev_otp_message') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs md:text-sm px-4 py-3">
            <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i>
            <div>
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    <article class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 md:p-7 leading-8 text-xs md:text-sm text-gray-700 overflow-x-auto prose prose-sm max-w-none prose-p:my-2 prose-headings:text-gray-800">
        {{--
            Raw output is safe here and ONLY here: rendered_html was produced by
            App\Services\Contract\TemplateRenderer, which substitutes into an
            admin-authored template using strtr and passes every substituted
            value through e() first. No customer-supplied string reaches this
            unescaped, and the template body is never compiled as Blade.
            The stored text is additionally hash-verified before display.
        --}}
        @if ($contract->isIntact())
            {!! $contract->rendered_html !!}
        @else
            <p class="text-red-600 font-bold">
                <i class="fa-solid fa-triangle-exclamation"></i>
                متن این قرارداد معتبر نیست. لطفاً با پشتیبانی تماس بگیرید.
            </p>
        @endif
    </article>

    @if ($contract->state === ContractState::Generated)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mt-5">
            <p class="text-xs md:text-sm text-gray-500 mb-3">برای ادامه، متن قرارداد را تأیید کنید.</p>
            <form method="POST" action="{{ route('rental.applications.contract.accept', $application) }}" data-loading-label="در حال ثبت…">
                @csrf
                <label class="flex items-start gap-2 text-xs md:text-sm text-gray-600 mb-4 cursor-pointer">
                    <input type="checkbox" required
                           class="mt-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-200 rounded">
                    <span>متن قرارداد را خوانده‌ام و می‌پذیرم.</span>
                </label>
                <button type="submit"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-sm font-bold rounded-xl px-6 py-2.5 hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                    <i class="fa-solid fa-check"></i> پذیرش قرارداد
                </button>
            </form>
        </div>
    @endif

    @if ($contract->state === ContractState::Accepted)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mt-5 space-y-4">
            <p class="text-xs md:text-sm text-gray-500">
                برای امضای قرارداد، ابتدا کد تأیید را درخواست کنید و سپس آن را در کادر زیر وارد نمایید.
            </p>

            <form method="POST" action="{{ route('rental.applications.contract.sign.otp', $application) }}" data-loading-label="در حال ارسال…">
                @csrf
                <button type="submit"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold rounded-xl px-6 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-200 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                    <i class="fa-solid fa-paper-plane"></i> ارسال کد تأیید امضا
                </button>
            </form>

            <form method="POST" action="{{ route('rental.applications.contract.sign', $application) }}" data-loading-label="در حال امضا…"
                  class="flex flex-col sm:flex-row gap-2">
                @csrf
                <div class="flex-1">
                    <label for="sign_code" class="sr-only">کد تأیید امضا</label>
                    <input id="sign_code" type="text" name="code" inputmode="numeric" dir="ltr" placeholder="کد تأیید"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-brandBlue focus:ring-2 focus:ring-blue-100">
                </div>
                <button type="submit"
                        class="inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-sm font-bold rounded-xl px-6 py-2.5 hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                    <i class="fa-solid fa-signature"></i> امضای قرارداد
                </button>
            </form>
        </div>
    @endif

    @if ($contract->state === ContractState::Signed)
        <div class="mt-5 flex items-start gap-2 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs md:text-sm px-4 py-3">
            <i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i>
            <span>
                این قرارداد در تاریخ
                {{ \App\Support\Rental\Jalali::formatLong($contract->signed_at->format('Y-m-d')) }}
                امضا شده است.
            </span>
        </div>

        <a href="{{ route('rental.applications.show', $application) }}"
           class="inline-flex items-center gap-2 mt-4 text-xs md:text-sm text-brandBlue font-bold hover:underline">
            <i class="fa-solid fa-arrow-right"></i> بازگشت به درخواست اجاره
        </a>
    @endif

    @if ($contract->state === ContractState::Void)
        <div class="mt-5 flex items-start gap-2 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs md:text-sm px-4 py-3">
            <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i>
            <span>این قرارداد باطل شده است و دیگر قابل پذیرش یا امضا نیست.</span>
        </div>

        <a href="{{ route('rental.applications.show', $application) }}"
           class="inline-flex items-center gap-2 mt-4 text-xs md:text-sm text-brandBlue font-bold hover:underline">
            <i class="fa-solid fa-arrow-right"></i> بازگشت به درخواست اجاره
        </a>
    @endif
</main>
@endsection

@push('scripts')
<script>
    // Presentational only: disable a plain (non-AJAX) form's submit button and
    // show a loading label while the request is in flight. Each form here
    // redirects back with success/errors on completion.
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
