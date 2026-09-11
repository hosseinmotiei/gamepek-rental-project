@extends('layouts.app')

@section('title', 'گیم‌پک | درخواست‌های اجاره من')

@php
    use App\Enums\RentalApplicationState;

    // Presentation only -- same grouping used on the application page, so the
    // two pages read consistently. Does not change what state() reports.
    $badgeTone = fn ($state) => match ($state) {
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

    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-2xl bg-blue-50 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-clipboard-list text-brandBlue"></i>
        </div>
        <h1 class="text-lg md:text-2xl font-black text-gray-800">درخواست‌های اجاره من</h1>
    </div>

    @if ($applications->isEmpty())
    <div class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
        <i class="fa-solid fa-file-circle-question text-5xl text-gray-200 mb-4"></i>
        <p class="text-gray-500 text-sm mb-4">هنوز درخواست اجاره‌ای ثبت نکرده‌اید.</p>
        <a href="{{ route('products.index') }}"
           class="inline-flex items-center gap-2 bg-brandBlue text-white text-xs font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 transition-colors">
            <i class="fa-solid fa-gamepad"></i> مشاهده دستگاه‌های قابل اجاره
        </a>
    </div>
    @else
    <div class="flex flex-col gap-4">
        @foreach ($applications as $application)
        <div class="bg-white rounded-2xl p-4 md:p-5 shadow-sm border border-gray-100 flex gap-4">
            <img src="{{ media_url($application->product?->main_image) }}"
                 alt="{{ $application->product?->title_fa ?? 'دستگاه اجاره‌ای' }}"
                 class="w-14 h-14 md:w-16 md:h-16 object-contain bg-gray-50 rounded-xl shrink-0 mix-blend-multiply">

            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap justify-between items-start gap-2 mb-2">
                    <div>
                        <span class="font-bold text-gray-800" dir="ltr">{{ $application->application_number }}</span>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $application->created_at->diffForHumans() }}</p>
                    </div>
                    <span class="text-[11px] md:text-xs font-bold px-3 py-1 rounded-full {{ $badgeTone($application->state) }}">
                        {{ $application->state->label() }}
                    </span>
                </div>

                <p class="text-xs md:text-sm text-gray-600 font-medium line-clamp-1">
                    {{ $application->product?->title_fa ?? 'دستگاهی انتخاب نشده است' }}
                </p>

                @if ($application->selected_start_date && $application->selected_end_date)
                    <p class="flex items-center gap-1.5 text-[11px] md:text-xs text-gray-400 mt-1">
                        <i class="fa-regular fa-calendar"></i>
                        <span dir="ltr">{{ \App\Support\Rental\Jalali::formatLong($application->selected_start_date->format('Y-m-d')) }}</span>
                        <i class="fa-solid fa-arrow-left-long text-[9px]"></i>
                        <span dir="ltr">{{ \App\Support\Rental\Jalali::formatLong($application->selected_end_date->format('Y-m-d')) }}</span>
                    </p>
                @endif

                <div class="flex justify-end mt-2">
                    <a href="{{ route('rental.applications.show', $application) }}"
                       class="text-xs text-brandBlue font-bold hover:underline inline-flex items-center gap-1">
                        مشاهده جزئیات <i class="fa-solid fa-arrow-left text-[10px]"></i>
                    </a>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    <div class="mt-6">{{ $applications->links() }}</div>
    @endif
</main>
@endsection
