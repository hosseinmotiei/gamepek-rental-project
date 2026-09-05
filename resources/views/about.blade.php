@extends('layouts.app')

@section('title', setting('about.meta_title', 'گیم‌پک اجاره | درباره ما'))
@section('meta_description', setting('about.meta_description', 'درباره گیم‌پک اجاره — اجاره کنسول بازی و لوازم جانبی.'))

@section('content')
{{--
    درباره ما. The body text is an admin setting, not a hardcoded claim: the
    default below says only what is verifiably true of this application (it
    rents consoles and accessories, in Tehran, with an identity-verified,
    contract-backed flow). No figures, awards, dates or promises are invented
    here — the owner writes those from پنل ادمین > تنظیمات.
--}}
<div class="max-w-[900px] mx-auto px-4 py-8 md:py-12">
    <div class="bg-white border border-gray-100 rounded-2xl md:rounded-3xl shadow-sm p-5 md:p-10">

        <div class="text-center border-b border-gray-100 pb-6 md:pb-8 mb-8">
            <span class="inline-flex items-center gap-2 bg-brandLightBlue text-brandBlue rounded-full px-4 py-1.5 text-xs font-bold mb-4">
                <i class="fa-solid fa-circle-info"></i> گیم‌پک اجاره
            </span>
            <h1 class="text-xl md:text-3xl font-black text-gray-800 mb-2">
                {{ setting('about.title', 'درباره گیم‌پک اجاره') }}
            </h1>
        </div>

        <div class="text-gray-700 text-sm md:text-base leading-8 md:leading-9">
            <p class="mb-6 text-justify">
                {{ setting('about.intro', 'گیم‌پک اجاره، سرویس اجاره کنسول بازی و لوازم جانبی است: به‌جای خرید یک دستگاه گران، آن را برای همان مدتی که لازم دارید اجاره می‌کنید.') }}
            </p>

            <div class="grid gap-4 sm:grid-cols-3 mb-8">
                <div class="bg-gray-50 rounded-2xl p-5 text-center">
                    <i class="fa-solid fa-calendar-check text-brandBlue text-2xl mb-3"></i>
                    <h2 class="font-bold text-gray-800 text-sm mb-1">اجاره روزانه</h2>
                    <p class="text-xs text-gray-500 leading-6">بازه اجاره را خودتان انتخاب می‌کنید و هزینه بر اساس همان مدت محاسبه می‌شود.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-5 text-center">
                    <i class="fa-solid fa-shield-halved text-brandBlue text-2xl mb-3"></i>
                    <h2 class="font-bold text-gray-800 text-sm mb-1">احراز هویت و قرارداد</h2>
                    <p class="text-xs text-gray-500 leading-6">هر اجاره با احراز هویت، تأیید مالکیت حساب بانکی و قرارداد امضاشده ثبت می‌شود.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-5 text-center">
                    <i class="fa-solid fa-location-dot text-brandBlue text-2xl mb-3"></i>
                    <h2 class="font-bold text-gray-800 text-sm mb-1">تحویل در تهران</h2>
                    <p class="text-xs text-gray-500 leading-6">در حال حاضر تحویل و بازگشت دستگاه در تهران انجام می‌شود.</p>
                </div>
            </div>

            @if($aboutBody = setting('about.body', ''))
                <div class="mb-8 text-justify whitespace-pre-line">{{ $aboutBody }}</div>
            @endif

            <div class="flex flex-wrap gap-3 pt-6 border-t border-gray-100">
                <a href="{{ route('products.index') }}"
                   class="inline-flex items-center gap-2 bg-brandBlue text-white text-sm font-bold px-5 py-2.5 rounded-xl hover:bg-blue-600 transition-colors">
                    <i class="fa-solid fa-gamepad"></i> مشاهده دستگاه‌های قابل اجاره
                </a>
                <a href="{{ route('contact') }}"
                   class="inline-flex items-center gap-2 border border-gray-200 text-gray-700 text-sm font-bold px-5 py-2.5 rounded-xl hover:bg-gray-50 transition-colors">
                    <i class="fa-solid fa-headset"></i> تماس با پشتیبانی
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
