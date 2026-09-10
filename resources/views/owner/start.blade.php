@extends('layouts.app')

@section('title', 'گیم‌پک اجاره | مالک دستگاه')

@section('content')
<div class="max-w-2xl mx-auto px-4 py-10">
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 md:p-8">
        <h1 class="text-xl md:text-2xl font-black text-gray-800 mb-2">دستگاه خود را به گیم‌پک بسپارید</h1>
        <p class="text-sm text-gray-500 leading-7 mb-6">
            اگر کنسول بازی دارید، می‌توانید آن را برای اجاره در اختیار گیم‌پک قرار دهید.
            پس از ایجاد حساب مالک، دستگاه خود را ثبت می‌کنید و کارشناسان ما آن را بررسی می‌کنند.
        </p>

        <div class="bg-brandLightBlue/50 border border-brandBlue/20 rounded-xl p-4 mb-6">
            <p class="text-xs text-gray-600 leading-6">
                <i class="fa-solid fa-circle-info text-brandBlue ml-1"></i>
                قیمت‌گذاری اجاره توسط گیم‌پک تعیین می‌شود. ثبت دستگاه به معنای تأیید آن نیست؛
                هر دستگاه پس از بررسی کارشناسی وارد ناوگان اجاره می‌شود.
            </p>
        </div>

        @if (session('error'))
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('owner.store') }}">
            @csrf
            <label for="display_name" class="block text-sm font-medium text-gray-700 mb-1.5">نام نمایشی (اختیاری)</label>
            <input type="text" id="display_name" name="display_name" value="{{ old('display_name') }}"
                   class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-brandBlue"
                   placeholder="مثلاً: فروشگاه بازی مرکزی">
            @error('display_name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror

            <button type="submit" class="w-full mt-5 bg-brandBlue text-white rounded-xl px-4 py-3 text-sm font-bold hover:bg-blue-700 transition-colors">
                ایجاد حساب مالک
            </button>
        </form>
    </div>
</div>
@endsection
