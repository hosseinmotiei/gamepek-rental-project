@extends('layouts.app')

@section('title', 'گیم‌پک | درخواست اجاره')

@php
    use App\Enums\RentalApplicationState;

    $reservation = $application->reservation;
    $order = $application->order;
    $identity = $application->user?->identity;
    $guarantee = $application->guarantee;
    $contract = $application->contract;

    $bankVerified = $application->user?->bankAccounts?->contains(fn ($a) => $a->isVerified()) ?? false;
    $bankAny = $application->user?->bankAccounts?->isNotEmpty() ?? false;
    $isPaid = $order?->payment_status === 'paid';

    // Each step is done / current / locked. The chain's own facts decide —
    // this mirrors RentalChainOrchestrator::nextState() and never writes state.
    $steps = [
        [
            'key' => 'identity',
            'icon' => 'fa-id-card',
            'title' => 'احراز هویت (سطح ۲)',
            'done' => $identity?->isVerified() ?? false,
            'status' => $identity?->state->label() ?? 'ثبت نشده',
        ],
        [
            'key' => 'bank',
            'icon' => 'fa-building-columns',
            'title' => 'تأیید مالکیت کارت / شبا',
            'done' => $bankVerified,
            'status' => $bankVerified ? 'تأیید شده' : ($bankAny ? 'در انتظار استعلام' : 'ثبت نشده'),
        ],
        [
            'key' => 'reservation',
            'icon' => 'fa-gamepad',
            'title' => 'رزرو دستگاه',
            'done' => $reservation !== null,
            'status' => $reservation ? 'ثبت شده' : 'ثبت نشده',
        ],
        [
            'key' => 'payment',
            'icon' => 'fa-credit-card',
            'title' => 'پرداخت',
            'done' => $isPaid,
            'status' => $isPaid ? 'پرداخت شد' : ($order ? 'در انتظار پرداخت' : 'شروع نشده'),
        ],
        [
            'key' => 'guarantee',
            'icon' => 'fa-file-shield',
            'title' => 'ثبت و بررسی ضمانت',
            'done' => $guarantee?->isVerified() ?? false,
            'status' => $guarantee?->state->value ?? 'ثبت نشده',
        ],
        [
            'key' => 'contract',
            'icon' => 'fa-file-signature',
            'title' => 'قرارداد، پذیرش و امضا',
            'done' => $contract?->state->value === 'signed',
            'status' => $contract?->state->value ?? 'صادر نشده',
        ],
        [
            'key' => 'approval',
            'icon' => 'fa-clipboard-check',
            'title' => 'تأیید نهایی',
            'done' => $application->state === RentalApplicationState::Approved,
            'status' => $application->state === RentalApplicationState::Approved ? 'تأیید شده' : 'در انتظار بررسی',
        ],
    ];

    // The first unfinished step is the one the customer can act on; the rest
    // stay locked so the flow reads in one direction.
    $currentKey = collect($steps)->firstWhere('done', false)['key'] ?? null;

    // Presentation only -- groups the same enum value the badge already shows
    // into a colour so the header reads at a glance. Does not change what
    // state() the chain reports or how it is derived.
    $badgeTone = match ($application->state) {
        RentalApplicationState::Approved,
        RentalApplicationState::Active,
        RentalApplicationState::Returned,
        RentalApplicationState::Closed => 'bg-emerald-50 text-emerald-700',
        RentalApplicationState::Rejected,
        RentalApplicationState::Cancelled => 'bg-red-50 text-red-700',
        default => 'bg-blue-50 text-brandBlue',
    };
@endphp

@section('content')
<main class="max-w-3xl mx-auto px-4 py-6 md:py-10" dir="rtl">

    <a href="{{ route('rental.applications.index') }}"
       class="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-brandBlue mb-3 transition-colors">
        <i class="fa-solid fa-arrow-right text-[10px]"></i> همه درخواست‌های اجاره من
    </a>

    <div class="flex flex-wrap items-center justify-between gap-2 mb-6">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-2xl bg-blue-50 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-clipboard-list text-brandBlue"></i>
            </div>
            <div>
                <h1 class="text-lg md:text-2xl font-black text-gray-800">درخواست اجاره</h1>
                <p class="text-[11px] md:text-xs text-gray-400 mt-0.5" dir="ltr">{{ $application->application_number }}</p>
            </div>
        </div>
        <span class="text-[11px] md:text-xs font-bold px-3 py-1.5 rounded-full {{ $badgeTone }}">
            {{ $application->state->label() }}
        </span>
    </div>

    @if (session('success'))
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs md:text-sm px-4 py-3">
            <i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i>
            <span>{{ session('success') }}</span>
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

    {{-- ─── Summary: product / device + rental details ─────────────────── --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5 overflow-hidden">
        @if ($reservation)
            <div class="p-5 flex gap-4 border-b border-gray-50">
                <img src="{{ media_url($reservation->product?->main_image) }}"
                     alt="{{ $reservation->product_snapshot['title'] ?? ($reservation->product?->title_fa ?? 'دستگاه اجاره‌ای') }}"
                     class="w-16 h-16 md:w-20 md:h-20 object-contain bg-gray-50 rounded-xl shrink-0 mix-blend-multiply">
                <div class="min-w-0">
                    <p class="text-sm md:text-base font-black text-gray-800 line-clamp-2">
                        {{ $reservation->product_snapshot['title'] ?? ($reservation->product?->title_fa ?? '-') }}
                    </p>
                    <div class="flex items-center gap-1.5 mt-2 text-xs md:text-sm text-gray-500">
                        <i class="fa-regular fa-calendar text-gray-400"></i>
                        <span dir="ltr">{{ \App\Support\Rental\Jalali::formatLong($reservation->start_date->format('Y-m-d')) }}</span>
                        <i class="fa-solid fa-arrow-left-long text-[10px] text-gray-300"></i>
                        <span dir="ltr">{{ \App\Support\Rental\Jalali::formatLong($reservation->end_date->format('Y-m-d')) }}</span>
                    </div>
                    <span class="inline-block mt-2 text-[11px] font-bold text-brandBlue bg-blue-50 rounded-full px-2.5 py-0.5">
                        {{ persian_number($reservation->days) }} روز اجاره
                    </span>
                </div>
            </div>

            <div class="p-5 space-y-2.5 text-xs md:text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-gray-500">اجاره ({{ persian_number($reservation->days) }} روز × {{ number_format($reservation->daily_rate) }} تومان)</span>
                    <span class="font-bold text-gray-700">{{ number_format($reservation->rental_total) }} تومان</span>
                </div>

                @if ($reservation->discount > 0)
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500">تخفیف</span>
                        <span class="font-bold text-emerald-600">−{{ number_format($reservation->discount) }} تومان</span>
                    </div>
                @endif

                @if ($reservation->delivery_fee > 0)
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500">هزینه ارسال</span>
                        <span class="font-bold text-gray-700">{{ number_format($reservation->delivery_fee) }} تومان</span>
                    </div>
                @endif

                <div class="flex items-center justify-between pt-2 border-t border-gray-50">
                    <span class="text-gray-600 font-bold">مبلغ قابل پرداخت</span>
                    <span class="font-black text-brandBlue text-sm md:text-base">
                        {{ number_format($reservation->payable_now) }} تومان
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-500">ودیعه (بلوکه، دریافت نمی‌شود)</span>
                    <span class="font-bold text-gray-700">
                        {{ number_format($reservation->deposit_amount) }} تومان
                    </span>
                </div>
            </div>
        @else
            <div class="p-5 flex items-center gap-3 text-gray-400">
                <i class="fa-solid fa-gamepad text-2xl"></i>
                <p class="text-xs md:text-sm">هنوز دستگاهی برای این درخواست انتخاب نشده است.</p>
            </div>
        @endif
    </div>

    {{-- ─── The journey ──────────────────────────────────────────────── --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5">
        <header class="px-5 py-4 border-b border-gray-100">
            <h2 class="text-sm md:text-base font-bold text-gray-800 flex items-center gap-2">
                <i class="fa-solid fa-list-check text-gray-400"></i>
                مراحل درخواست
            </h2>
        </header>

        <ol class="divide-y divide-gray-50">
            @foreach ($steps as $index => $step)
            @php $isCurrent = $step['key'] === $currentKey; @endphp
            <li class="p-4 md:p-5 {{ $step['done'] ? 'bg-emerald-50/30' : ($isCurrent ? 'bg-blue-50/30 ring-1 ring-inset ring-blue-100' : 'opacity-60') }}">
                <div class="flex items-start gap-3">
                    <span class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold
                        {{ $step['done'] ? 'bg-emerald-500 text-white' : ($isCurrent ? 'bg-brandBlue text-white' : 'bg-gray-200 text-gray-500') }}">
                        @if ($step['done'])
                            <i class="fa-solid fa-check"></i>
                        @elseif ($isCurrent)
                            <i class="fa-solid {{ $step['icon'] }}"></i>
                        @else
                            {{ persian_number($index + 1) }}
                        @endif
                    </span>

                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                            <h3 class="text-sm font-bold text-gray-800">{{ $step['title'] }}</h3>
                            <span class="text-[11px] text-gray-500">{{ $step['status'] }}</span>
                        </div>

                        @if ($isCurrent)
                        <div class="mt-3">
                            @switch($step['key'])

                                @case('identity')
                                    <p class="text-xs text-gray-500 mb-3">کد ملی و تاریخ تولد خود را ثبت و استعلام‌ها را اجرا کنید.</p>
                                    <a href="{{ route('verification.index') }}"
                                       class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors">
                                        <i class="fa-solid fa-id-card"></i> رفتن به احراز هویت
                                    </a>
                                    @break

                                @case('bank')
                                    <p class="text-xs text-gray-500 mb-3">شماره کارت یا شبای خود را ثبت و مالکیت آن را استعلام کنید.</p>
                                    <a href="{{ route('verification.index') }}"
                                       class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors">
                                        <i class="fa-solid fa-building-columns"></i> ثبت حساب بانکی
                                    </a>
                                    @break

                                @case('reservation')
                                    <p class="text-xs text-gray-500 mb-3">هنوز دستگاهی برای این درخواست رزرو نشده است.</p>
                                    <a href="{{ route('products.index') }}"
                                       class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors">
                                        <i class="fa-solid fa-gamepad"></i> انتخاب دستگاه
                                    </a>
                                    @break

                                @case('payment')
                                    <p class="text-xs text-gray-500 mb-3">
                                        مبلغ {{ number_format($reservation?->payable_now ?? 0) }} تومان پرداخت می‌شود.
                                        ودیعه در این مرحله دریافت نمی‌شود.
                                    </p>
                                    <form method="POST" action="{{ route('rental.applications.pay', $application) }}" data-loading-label="در حال انتقال…">
                                        @csrf
                                        <button type="submit"
                                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                                            <i class="fa-solid fa-credit-card"></i>
                                            {{ $order ? 'ادامه پرداخت' : 'پرداخت' }}
                                        </button>
                                    </form>
                                    @break

                                @case('guarantee')
                                    <p class="text-xs text-gray-500 mb-3">شناسه صیاد چک ضمانت را وارد کنید تا استعلام‌ها اجرا شوند.</p>
                                    <form method="POST" action="{{ route('rental.applications.guarantee', $application) }}"
                                          data-loading-label="در حال ثبت…"
                                          class="grid gap-3 md:grid-cols-3">
                                        @csrf
                                        <input type="hidden" name="type" value="cheque">
                                        <div class="md:col-span-2">
                                            <label for="sayad_id" class="block text-[11px] text-gray-500 mb-1">
                                                شناسه صیاد (۱۶ رقم) <span class="text-red-500">*</span>
                                            </label>
                                            <input id="sayad_id" type="text" name="sayad_id" inputmode="numeric" maxlength="16" dir="ltr"
                                                   value="{{ old('sayad_id') }}"
                                                   placeholder="{{ $guarantee?->sayad_id_mask ?: '' }}"
                                                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-brandBlue focus:ring-2 focus:ring-blue-100">
                                        </div>
                                        <div>
                                            <label for="amount" class="block text-[11px] text-gray-500 mb-1">مبلغ (تومان)</label>
                                            <input id="amount" type="number" name="amount" min="0" dir="ltr"
                                                   value="{{ old('amount', $guarantee?->amount) }}"
                                                   class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-brandBlue focus:ring-2 focus:ring-blue-100">
                                        </div>
                                        <div class="md:col-span-3">
                                            <button type="submit"
                                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                                                <i class="fa-solid fa-file-shield"></i> ثبت و استعلام ضمانت
                                            </button>
                                        </div>
                                    </form>
                                    @if ($guarantee && ! $guarantee->isVerified())
                                        <p class="mt-3 text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 leading-6">
                                            استعلام‌ها ثبت شدند. تأیید نهایی ضمانت تا تعیین سیاست کسب‌وکار، توسط کارشناس انجام می‌شود.
                                        </p>
                                    @endif
                                    @break

                                @case('contract')
                                    <p class="text-xs text-gray-500 mb-3">قرارداد را مشاهده، تأیید و با کد پیامکی امضا کنید.</p>
                                    <a href="{{ route('rental.applications.contract', $application) }}"
                                       class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors">
                                        <i class="fa-solid fa-file-signature"></i>
                                        {{ $contract ? 'ادامه قرارداد' : 'صدور قرارداد' }}
                                    </a>
                                    @break

                                @case('approval')
                                    <p class="text-xs text-gray-600 bg-blue-50 border border-blue-100 rounded-xl px-3 py-2.5 leading-6">
                                        همه مراحل انجام شد. تأیید نهایی توسط کارشناس گیم‌پک انجام می‌شود و نتیجه به شما اطلاع داده خواهد شد.
                                    </p>
                                    @break

                            @endswitch
                        </div>
                        @endif
                    </div>
                </div>
            </li>
            @endforeach
        </ol>
    </section>

    {{-- Shown once every step above is done and nothing is "current" anymore
         -- today that only happens at Approved, since Active/Returned/Closed
         are not yet reachable (B14). Reuses $application->state and
         approved_at, which are already set by RentalChainOrchestrator::approve();
         no new fact or workflow is introduced here. --}}
    @if ($application->state === RentalApplicationState::Approved)
        <div class="mb-5 flex items-start gap-3 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-5 py-4">
            <i class="fa-solid fa-circle-check text-lg mt-0.5 shrink-0"></i>
            <div class="text-xs md:text-sm leading-6">
                <p class="font-bold mb-0.5">درخواست اجاره شما تأیید نهایی شد.</p>
                @if ($application->approved_at)
                    <p>
                        تاریخ تأیید:
                        {{ \App\Support\Rental\Jalali::formatLong($application->approved_at->format('Y-m-d')) }}
                    </p>
                @endif
            </div>
        </div>
    @endif

    {{-- Chain history. Every transition is recorded, so the customer can see
         exactly where the request stands and how it got there. --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm">
        <header class="px-5 py-4 border-b border-gray-100">
            <h2 class="text-sm md:text-base font-bold text-gray-800 flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left text-gray-400"></i>
                تاریخچه
            </h2>
        </header>
        <div class="p-5 space-y-3">
            @forelse ($application->transitions->sortByDesc('created_at') as $transition)
                <div class="flex items-center justify-between gap-3 text-xs md:text-sm border-b border-gray-50 pb-2 last:border-0 last:pb-0">
                    <span class="flex items-center gap-2 text-gray-600">
                        <i class="fa-solid fa-circle text-[6px] text-brandBlue"></i>
                        {{ \App\Enums\RentalApplicationState::from($transition->to_state)->label() }}
                    </span>
                    <span class="text-[11px] text-gray-400 shrink-0" dir="ltr">
                        {{ \App\Support\Rental\Jalali::formatLong($transition->created_at->format('Y-m-d')) }}
                    </span>
                </div>
            @empty
                <p class="text-xs md:text-sm text-gray-400">هنوز رویدادی ثبت نشده است.</p>
            @endforelse
        </div>
    </section>
</main>
@endsection

@push('scripts')
<script>
    // Presentational only: on submit of a plain (non-AJAX) form on this page,
    // disable its button and show a loading label so a slow request cannot be
    // fired twice. The page always navigates away on response (redirect or
    // back()), so there is nothing to re-enable on success; a validation
    // failure reloads the page fresh.
    document.querySelectorAll('main form[data-loading-label]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + form.dataset.loadingLabel;
        });
    });
</script>
@endpush
