@extends('layouts.app')

@section('title', 'گیم‌پک | داشبورد اجاره')

@php
    use App\Enums\RentalApplicationState;

    // Presentation only -- same grouping used on the application and list
    // pages, so all three read consistently. Does not change what state()
    // reports.
    $badgeTone = fn ($state) => match ($state) {
        RentalApplicationState::Approved,
        RentalApplicationState::Active,
        RentalApplicationState::Returned,
        RentalApplicationState::Closed => 'bg-emerald-50 text-emerald-700',
        RentalApplicationState::Rejected,
        RentalApplicationState::Cancelled => 'bg-red-50 text-red-700',
        default => 'bg-blue-50 text-brandBlue',
    };

    $identityVerified = $identity?->isVerified() ?? false;
    $recent = $applications->where('id', '!=', $active?->id)->take(5);
@endphp

@section('content')
<main class="max-w-3xl mx-auto px-4 py-6 md:py-10" dir="rtl">

    {{-- ─── Header ───────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-2xl bg-blue-50 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-house-chimney-window text-brandBlue"></i>
        </div>
        <div>
            <h1 class="text-lg md:text-2xl font-black text-gray-800">
                سلام{{ $user->full_name ? '، '.$user->full_name : '' }}
            </h1>
            <p class="text-[11px] md:text-sm text-gray-500">وضعیت اجاره شما در یک نگاه</p>
        </div>
    </div>

    {{-- ─── Verification status ──────────────────────────────────────── --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5">
        <header class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-2">
            <h2 class="text-sm md:text-base font-bold text-gray-800 flex items-center gap-2">
                <i class="fa-solid fa-shield-halved text-gray-400"></i>
                وضعیت احراز هویت
            </h2>
            <a href="{{ route('verification.index') }}" class="text-xs text-brandBlue font-bold hover:underline shrink-0">
                مدیریت <i class="fa-solid fa-arrow-left text-[10px]"></i>
            </a>
        </header>
        <div class="p-5 flex flex-wrap gap-3">
            <span class="inline-flex items-center gap-1.5 text-[11px] md:text-xs font-bold px-3 py-1.5 rounded-full
                {{ $identityVerified ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                <i class="fa-solid fa-id-card"></i>
                احراز هویت: {{ $identity?->state->label() ?? 'ثبت نشده' }}
            </span>
            <span class="inline-flex items-center gap-1.5 text-[11px] md:text-xs font-bold px-3 py-1.5 rounded-full
                {{ $bankVerified ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                <i class="fa-solid fa-building-columns"></i>
                حساب بانکی: {{ $bankVerified ? 'تأیید شده' : ($bankAny ? 'در انتظار استعلام' : 'ثبت نشده') }}
            </span>
        </div>
    </section>

    {{-- ─── Active application ───────────────────────────────────────── --}}
    @if ($active)
        @php
            $reservation = $active->reservation;
        @endphp
        <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5 overflow-hidden">
            <header class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-2">
                <h2 class="text-sm md:text-base font-bold text-gray-800 flex items-center gap-2">
                    <i class="fa-solid fa-gamepad text-gray-400"></i>
                    درخواست جاری
                </h2>
                <span class="text-[11px] md:text-xs font-bold px-3 py-1 rounded-full {{ $badgeTone($active->state) }}">
                    {{ $active->state->label() }}
                </span>
            </header>

            <div class="p-5 flex gap-4">
                <img src="{{ media_url($active->product?->main_image) }}"
                     alt="{{ $active->product?->title_fa ?? 'دستگاه اجاره‌ای' }}"
                     class="w-16 h-16 md:w-20 md:h-20 object-contain bg-gray-50 rounded-xl shrink-0 mix-blend-multiply">
                <div class="min-w-0 flex-1">
                    <p class="text-sm md:text-base font-black text-gray-800 line-clamp-2">
                        {{ $active->product?->title_fa ?? 'دستگاهی انتخاب نشده است' }}
                    </p>
                    <p class="text-[11px] text-gray-400 mt-0.5" dir="ltr">{{ $active->application_number }}</p>

                    @if ($reservation)
                        <p class="flex items-center gap-1.5 text-[11px] md:text-xs text-gray-500 mt-2">
                            <i class="fa-regular fa-calendar text-gray-400"></i>
                            <span dir="ltr">{{ \App\Support\Rental\Jalali::formatLong($reservation->start_date->format('Y-m-d')) }}</span>
                            <i class="fa-solid fa-arrow-left-long text-[9px] text-gray-300"></i>
                            <span dir="ltr">{{ \App\Support\Rental\Jalali::formatLong($reservation->end_date->format('Y-m-d')) }}</span>
                        </p>
                    @elseif ($active->selected_start_date && $active->selected_end_date)
                        <p class="flex items-center gap-1.5 text-[11px] md:text-xs text-gray-500 mt-2">
                            <i class="fa-regular fa-calendar text-gray-400"></i>
                            <span dir="ltr">{{ \App\Support\Rental\Jalali::formatLong($active->selected_start_date->format('Y-m-d')) }}</span>
                            <i class="fa-solid fa-arrow-left-long text-[9px] text-gray-300"></i>
                            <span dir="ltr">{{ \App\Support\Rental\Jalali::formatLong($active->selected_end_date->format('Y-m-d')) }}</span>
                        </p>
                    @endif
                </div>
            </div>

            <div class="px-5 pb-5">
                <a href="{{ route('rental.applications.show', $active) }}"
                   class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-xs md:text-sm font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors">
                    <i class="fa-solid fa-arrow-left"></i> ادامه و مشاهده جزئیات
                </a>
            </div>
        </section>
    @elseif ($applications->isEmpty())
        <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5 p-10 text-center">
            <i class="fa-solid fa-gamepad text-5xl text-gray-200 mb-4"></i>
            <p class="text-gray-500 text-sm mb-4">هنوز درخواست اجاره‌ای ثبت نکرده‌اید.</p>
            <a href="{{ route('products.index') }}"
               class="inline-flex items-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 transition-colors">
                <i class="fa-solid fa-gamepad"></i> مشاهده دستگاه‌های قابل اجاره
            </a>
        </section>
    @else
        <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5 p-6 flex items-center gap-3 text-gray-500">
            <i class="fa-solid fa-circle-check text-emerald-500 text-xl shrink-0"></i>
            <p class="text-xs md:text-sm">در حال حاضر درخواست در جریانی ندارید. می‌توانید درخواست‌های قبلی خود را در پایین این صفحه ببینید.</p>
        </section>
    @endif

    {{-- ─── Recent applications ──────────────────────────────────────── --}}
    @if ($recent->isNotEmpty())
        <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5">
            <header class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-2">
                <h2 class="text-sm md:text-base font-bold text-gray-800 flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left text-gray-400"></i>
                    درخواست‌های اخیر
                </h2>
                <a href="{{ route('rental.applications.index') }}" class="text-xs text-brandBlue font-bold hover:underline shrink-0">
                    مشاهده همه <i class="fa-solid fa-arrow-left text-[10px]"></i>
                </a>
            </header>
            <div class="divide-y divide-gray-50">
                @foreach ($recent as $application)
                    <a href="{{ route('rental.applications.show', $application) }}"
                       class="flex items-center justify-between gap-3 px-5 py-3.5 hover:bg-gray-50 transition-colors">
                        <div class="min-w-0">
                            <p class="text-xs md:text-sm font-bold text-gray-800 line-clamp-1">
                                {{ $application->product?->title_fa ?? 'دستگاهی انتخاب نشده است' }}
                            </p>
                            <p class="text-[11px] text-gray-400 mt-0.5" dir="ltr">{{ $application->application_number }}</p>
                        </div>
                        <span class="text-[11px] font-bold px-2.5 py-1 rounded-full shrink-0 {{ $badgeTone($application->state) }}">
                            {{ $application->state->label() }}
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ─── Quick links ───────────────────────────────────────────────── --}}
    <section class="grid grid-cols-2 gap-3">
        <a href="{{ route('rental.applications.index') }}"
           class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex flex-col items-center gap-2 text-center hover:border-brandBlue transition-colors">
            <i class="fa-solid fa-clipboard-list text-brandBlue text-lg"></i>
            <span class="text-xs font-bold text-gray-700">درخواست‌های اجاره من</span>
        </a>
        <a href="{{ route('products.index') }}"
           class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex flex-col items-center gap-2 text-center hover:border-brandBlue transition-colors">
            <i class="fa-solid fa-gamepad text-brandBlue text-lg"></i>
            <span class="text-xs font-bold text-gray-700">دستگاه‌های قابل اجاره</span>
        </a>
    </section>
</main>
@endsection
