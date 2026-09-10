@extends('layouts.app')

@section('title', 'گیم‌پک | درخواست‌های اجاره من')

@section('content')
<main class="max-w-3xl mx-auto px-4 py-6 md:py-10" dir="rtl">

    <h1 class="text-lg md:text-2xl font-black text-gray-800 mb-6">درخواست‌های اجاره من</h1>

    @if ($applications->isEmpty())
    <div class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
        <i class="fa-solid fa-file-circle-question text-5xl text-gray-200 mb-4"></i>
        <p class="text-gray-500 text-sm">هنوز درخواست اجاره‌ای ثبت نکرده‌اید.</p>
    </div>
    @else
    <div class="flex flex-col gap-4">
        @foreach ($applications as $application)
        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <span class="font-bold text-gray-800" dir="ltr">{{ $application->application_number }}</span>
                    <p class="text-xs text-gray-400 mt-0.5">{{ $application->created_at->diffForHumans() }}</p>
                </div>
                <span class="text-[11px] md:text-xs font-bold px-3 py-1 rounded-full bg-blue-50 text-brandBlue">
                    {{ $application->state->label() }}
                </span>
            </div>

            <div class="flex justify-between items-center">
                <span class="text-xs md:text-sm text-gray-600">
                    {{ $application->product?->title_fa ?? 'دستگاهی انتخاب نشده است' }}
                </span>
                <a href="{{ route('rental.applications.show', $application) }}"
                   class="text-xs text-brandBlue font-bold hover:underline">
                    مشاهده جزئیات <i class="fa-solid fa-arrow-left text-[10px]"></i>
                </a>
            </div>
        </div>
        @endforeach
    </div>
    <div class="mt-6">{{ $applications->links() }}</div>
    @endif
</main>
@endsection
