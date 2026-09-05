@extends('layouts.app')

@section('title', 'گیم‌پک | قرارداد اجاره')

@section('content')
<main class="max-w-3xl mx-auto px-4 py-6 md:py-10" dir="rtl">

    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg md:text-2xl font-black text-gray-800">قرارداد اجاره</h1>
        <span class="text-[11px] md:text-xs font-bold px-3 py-1 rounded-full bg-blue-50 text-brandBlue">
            {{ $contract->state->label() }}
        </span>
    </div>

    <div class="text-[11px] text-gray-400 mb-4" dir="ltr">
        {{ $contract->number }} — v{{ $contract->template_version }}
    </div>

    <article class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 md:p-7 leading-8 text-xs md:text-sm text-gray-700 overflow-x-auto">
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
                متن این قرارداد معتبر نیست. لطفاً با پشتیبانی تماس بگیرید.
            </p>
        @endif
    </article>

    @if ($contract->state === \App\Enums\ContractState::Generated)
        <form method="POST" action="{{ route('rental.applications.contract.accept', $application) }}" class="mt-5">
            @csrf
            <label class="flex items-start gap-2 text-xs md:text-sm text-gray-600 mb-3">
                <input type="checkbox" required class="mt-1">
                <span>متن قرارداد را خوانده‌ام و می‌پذیرم.</span>
            </label>
            <button type="submit" class="w-full md:w-auto bg-brandBlue text-white text-sm font-bold rounded-xl px-6 py-2.5">
                پذیرش قرارداد
            </button>
        </form>
    @endif

    @if ($contract->state === \App\Enums\ContractState::Accepted)
        <div class="mt-5 grid gap-3">
            <form method="POST" action="{{ route('rental.applications.contract.sign.otp', $application) }}">
                @csrf
                <button type="submit" class="w-full md:w-auto bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold rounded-xl px-6 py-2.5">
                    ارسال کد تأیید امضا
                </button>
            </form>

            <form method="POST" action="{{ route('rental.applications.contract.sign', $application) }}" class="flex gap-2">
                @csrf
                <input type="text" name="code" inputmode="numeric" dir="ltr" placeholder="کد تأیید"
                       class="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-brandBlue">
                <button type="submit" class="bg-brandBlue text-white text-sm font-bold rounded-xl px-6 py-2.5">
                    امضای قرارداد
                </button>
            </form>
        </div>
    @endif

    @if ($contract->state === \App\Enums\ContractState::Signed)
        <div class="mt-5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs md:text-sm px-4 py-3">
            این قرارداد در تاریخ
            {{ \App\Support\Rental\Jalali::formatLong($contract->signed_at->format('Y-m-d')) }}
            امضا شده است.
        </div>
    @endif
</main>
@endsection
