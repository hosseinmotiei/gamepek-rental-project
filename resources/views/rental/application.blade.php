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
            'title' => 'احراز هویت (سطح ۲)',
            'done' => $identity?->isVerified() ?? false,
            'status' => $identity?->state->label() ?? 'ثبت نشده',
        ],
        [
            'key' => 'bank',
            'title' => 'تأیید مالکیت کارت / شبا',
            'done' => $bankVerified,
            'status' => $bankVerified ? 'تأیید شده' : ($bankAny ? 'در انتظار استعلام' : 'ثبت نشده'),
        ],
        [
            'key' => 'reservation',
            'title' => 'رزرو دستگاه',
            'done' => $reservation !== null,
            'status' => $reservation ? 'ثبت شده' : 'ثبت نشده',
        ],
        [
            'key' => 'payment',
            'title' => 'پرداخت',
            'done' => $isPaid,
            'status' => $isPaid ? 'پرداخت شد' : ($order ? 'در انتظار پرداخت' : 'شروع نشده'),
        ],
        [
            'key' => 'guarantee',
            'title' => 'ثبت و بررسی ضمانت',
            'done' => $guarantee?->isVerified() ?? false,
            'status' => $guarantee?->state->value ?? 'ثبت نشده',
        ],
        [
            'key' => 'contract',
            'title' => 'قرارداد، پذیرش و امضا',
            'done' => $contract?->state->value === 'signed',
            'status' => $contract?->state->value ?? 'صادر نشده',
        ],
        [
            'key' => 'approval',
            'title' => 'تأیید نهایی',
            'done' => $application->state === RentalApplicationState::Approved,
            'status' => $application->state === RentalApplicationState::Approved ? 'تأیید شده' : 'در انتظار بررسی',
        ],
    ];

    // The first unfinished step is the one the customer can act on; the rest
    // stay locked so the flow reads in one direction.
    $currentKey = collect($steps)->firstWhere('done', false)['key'] ?? null;
@endphp

@section('content')
<main class="max-w-3xl mx-auto px-4 py-6 md:py-10" dir="rtl">

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-lg md:text-2xl font-black text-gray-800">درخواست اجاره</h1>
        <span class="text-[11px] md:text-xs font-bold px-3 py-1 rounded-full bg-blue-50 text-brandBlue">
            {{ $application->state->label() }}
        </span>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs md:text-sm px-4 py-3">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs md:text-sm px-4 py-3">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- ─── Summary ──────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5 p-5 space-y-3 text-xs md:text-sm">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">شماره درخواست</span>
            <span class="font-bold text-gray-800" dir="ltr">{{ $application->application_number }}</span>
        </div>

        @if ($reservation)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">دستگاه</span>
                <span class="font-bold text-gray-800">
                    {{ $reservation->product_snapshot['title'] ?? ($reservation->product?->title_fa ?? '-') }}
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-gray-500">بازه اجاره</span>
                <span class="font-bold text-gray-800">
                    {{ \App\Support\Rental\Jalali::formatLong($reservation->start_date->format('Y-m-d')) }}
                    تا
                    {{ \App\Support\Rental\Jalali::formatLong($reservation->end_date->format('Y-m-d')) }}
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-gray-500">مبلغ قابل پرداخت</span>
                <span class="font-black text-brandBlue">
                    {{ number_format($reservation->payable_now) }} تومان
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-gray-500">ودیعه (بلوکه، دریافت نمی‌شود)</span>
                <span class="font-bold text-gray-700">
                    {{ number_format($reservation->deposit_amount) }} تومان
                </span>
            </div>
        @endif
    </div>

    {{-- ─── The journey ──────────────────────────────────────────────── --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5">
        <header class="px-5 py-4 border-b border-gray-100">
            <h2 class="text-sm md:text-base font-bold text-gray-800">مراحل درخواست</h2>
        </header>

        <ol class="divide-y divide-gray-50">
            @foreach ($steps as $index => $step)
            @php $isCurrent = $step['key'] === $currentKey; @endphp
            <li class="p-5 {{ $step['done'] ? 'bg-emerald-50/30' : ($isCurrent ? '' : 'opacity-60') }}">
                <div class="flex items-start gap-3">
                    <span class="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-bold
                        {{ $step['done'] ? 'bg-emerald-500 text-white' : ($isCurrent ? 'bg-brandBlue text-white' : 'bg-gray-200 text-gray-500') }}">
                        @if ($step['done'])<i class="fa-solid fa-check"></i>@else{{ persian_number($index + 1) }}@endif
                    </span>

                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-sm font-bold text-gray-800">{{ $step['title'] }}</h3>
                            <span class="text-[11px] text-gray-500">{{ $step['status'] }}</span>
                        </div>

                        @if ($isCurrent)
                        <div class="mt-3">
                            @switch($step['key'])

                                @case('identity')
                                    <p class="text-xs text-gray-500 mb-3">کد ملی و تاریخ تولد خود را ثبت و استعلام‌ها را اجرا کنید.</p>
                                    <a href="{{ route('verification.index') }}"
                                       class="inline-flex items-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 transition-colors">
                                        <i class="fa-solid fa-id-card"></i> رفتن به احراز هویت
                                    </a>
                                    @break

                                @case('bank')
                                    <p class="text-xs text-gray-500 mb-3">شماره کارت یا شبای خود را ثبت و مالکیت آن را استعلام کنید.</p>
                                    <a href="{{ route('verification.index') }}"
                                       class="inline-flex items-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 transition-colors">
                                        <i class="fa-solid fa-building-columns"></i> ثبت حساب بانکی
                                    </a>
                                    @break

                                @case('reservation')
                                    <p class="text-xs text-gray-500 mb-3">هنوز دستگاهی برای این درخواست رزرو نشده است.</p>
                                    <a href="{{ route('products.index') }}"
                                       class="inline-flex items-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 transition-colors">
                                        <i class="fa-solid fa-gamepad"></i> انتخاب دستگاه
                                    </a>
                                    @break

                                @case('payment')
                                    <p class="text-xs text-gray-500 mb-3">
                                        مبلغ {{ number_format($reservation?->payable_now ?? 0) }} تومان پرداخت می‌شود.
                                        ودیعه در این مرحله دریافت نمی‌شود.
                                    </p>
                                    <form method="POST" action="{{ route('rental.applications.pay', $application) }}">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 transition-colors">
                                            <i class="fa-solid fa-credit-card"></i>
                                            {{ $order ? 'ادامه پرداخت' : 'پرداخت' }}
                                        </button>
                                    </form>
                                    @break

                                @case('guarantee')
                                    <p class="text-xs text-gray-500 mb-3">شناسه صیاد چک ضمانت را وارد کنید تا استعلام‌ها اجرا شوند.</p>
                                    <form method="POST" action="{{ route('rental.applications.guarantee', $application) }}"
                                          class="grid gap-3 md:grid-cols-3">
                                        @csrf
                                        <input type="hidden" name="type" value="cheque">
                                        <div class="md:col-span-2">
                                            <label class="block text-[11px] text-gray-500 mb-1">شناسه صیاد (۱۶ رقم)</label>
                                            <input type="text" name="sayad_id" inputmode="numeric" maxlength="16" dir="ltr"
                                                   placeholder="{{ $guarantee?->sayad_id_mask ?: '' }}"
                                                   class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-brandBlue">
                                        </div>
                                        <div>
                                            <label class="block text-[11px] text-gray-500 mb-1">مبلغ (تومان)</label>
                                            <input type="number" name="amount" min="0" dir="ltr"
                                                   value="{{ old('amount', $guarantee?->amount) }}"
                                                   class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-brandBlue">
                                        </div>
                                        <div class="md:col-span-3">
                                            <button type="submit"
                                                    class="inline-flex items-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 transition-colors">
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
                                       class="inline-flex items-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 transition-colors">
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

    {{-- Chain history. Every transition is recorded, so the customer can see
         exactly where the request stands and how it got there. --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm">
        <header class="px-5 py-4 border-b border-gray-100">
            <h2 class="text-sm md:text-base font-bold text-gray-800">تاریخچه</h2>
        </header>
        <div class="p-5 space-y-3">
            @forelse ($application->transitions->sortByDesc('created_at') as $transition)
                <div class="flex items-center justify-between text-xs md:text-sm border-b border-gray-50 pb-2">
                    <span class="text-gray-600">
                        {{ \App\Enums\RentalApplicationState::from($transition->to_state)->label() }}
                    </span>
                    <span class="text-[11px] text-gray-400" dir="ltr">
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
