@extends('layouts.app')

@section('title', 'گیم‌پک | تماس با ما')
@section('meta_description', 'راه‌های ارتباط با گیم‌پک اجاره و پاسخ پرسش‌های متداول.')

@section('content')
@php
    $contactPhone = setting('general.contact_phone', setting('general.support_mobile', '۰۹۱۲۱۲۳۴۵۶۷')) ?: '۰۹۱۲۱۲۳۴۵۶۷';
    $instagram = setting('general.contact_instagram', 'gamepek.ir') ?: 'gamepek.ir';
    $address = setting('general.contact_address', setting('general.store_address', 'تهران، سعادت‌آباد')) ?: 'تهران، سعادت‌آباد';
    $phoneHref = preg_replace('/[^0-9+]/', '', strtr($contactPhone, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
    $instagramLabel = ltrim(str_replace(['https://instagram.com/', 'http://instagram.com/', 'https://www.instagram.com/', 'http://www.instagram.com/'], '', $instagram), '@/');
    $instagramUrl = str_starts_with($instagram, 'http://') || str_starts_with($instagram, 'https://')
        ? $instagram
        : 'https://instagram.com/' . ltrim($instagram, '@/');
    $faqItems = [
        ['چطور سفارش ثبت کنم؟', 'محصول موردنظر را به سبد خرید اضافه کنید و مراحل پرداخت را تکمیل کنید.'],
        ['کد دیجیتال چطور تحویل داده می‌شود؟', 'بعد از پرداخت موفق، کد دیجیتال از بخش سفارش‌ها در پروفایل قابل مشاهده است.'],
        ['ارسال سفارش‌ها از کجاست؟', 'مبدا ارسال سفارش‌ها سعادت‌آباد تهران است.'],
        ['امکان پیگیری سفارش وجود دارد؟', 'بله، از بخش پروفایل و سفارش‌های من می‌توانید وضعیت سفارش را بررسی کنید.'],
    ];
@endphp

<div class="max-w-[1100px] mx-auto px-4 py-8 md:py-12">
    <div class="bg-gradient-to-l from-brandBlue to-blue-700 rounded-3xl p-6 md:p-10 text-white mb-8 shadow-lg shadow-blue-500/20">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div>
                <span class="inline-flex items-center gap-2 bg-white/15 rounded-full px-4 py-1.5 text-xs font-bold mb-4"><i class="fa-solid fa-headset"></i> پشتیبانی گیم‌پک</span>
                <h1 class="text-2xl md:text-4xl font-black mb-3">تماس با ما</h1>
                <p class="text-sm md:text-base text-blue-100 leading-8 max-w-2xl">برای سوال درباره سفارش، خرید PS5، گیفت کارت، ریجن محصولات یا پیگیری پرداخت، از راه‌های زیر با ما در ارتباط باشید.</p>
            </div>
            <div class="w-20 h-20 md:w-28 md:h-28 rounded-3xl bg-white/15 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-comments text-4xl md:text-5xl"></i>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-4">
        <a href="tel:{{ $phoneHref }}" class="bg-white border border-gray-100 rounded-2xl p-5 shadow-sm hover:shadow-md transition-shadow flex items-center gap-4">
            <div class="w-12 h-12 bg-blue-50 text-brandBlue rounded-2xl flex items-center justify-center shrink-0"><i class="fa-solid fa-phone"></i></div>
            <div class="min-w-0">
                <p class="text-xs text-gray-400 mb-1">شماره تماس</p>
                <p class="font-bold text-gray-800 break-words" dir="ltr">{{ $contactPhone }}</p>
            </div>
        </a>
        <a href="{{ $instagramUrl }}" target="_blank" rel="noopener noreferrer" class="bg-white border border-gray-100 rounded-2xl p-5 shadow-sm hover:shadow-md transition-shadow flex items-center gap-4">
            <div class="w-12 h-12 bg-pink-50 text-pink-500 rounded-2xl flex items-center justify-center shrink-0"><i class="fa-brands fa-instagram"></i></div>
            <div class="min-w-0">
                <p class="text-xs text-gray-400 mb-1">اینستاگرام</p>
                <p class="font-bold text-gray-800 break-words" dir="ltr">{{ $instagramLabel }}</p>
            </div>
        </a>
    </div>

    <div class="bg-white border border-gray-100 rounded-2xl p-5 shadow-sm flex items-start gap-4 mb-8">
        <div class="w-12 h-12 bg-green-50 text-green-600 rounded-2xl flex items-center justify-center shrink-0"><i class="fa-solid fa-location-dot"></i></div>
        <div class="min-w-0">
            <p class="text-xs text-gray-400 mb-1">آدرس</p>
            <p class="font-bold text-gray-800 leading-8 break-words">{{ $address }}</p>
        </div>
    </div>

    <div id="faq" class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5 md:p-8">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-11 h-11 bg-brandLightBlue text-brandBlue rounded-2xl flex items-center justify-center"><i class="fa-solid fa-circle-question"></i></div>
            <div>
                <h2 class="text-lg md:text-xl font-black text-gray-800">سوالات متداول</h2>
                <p class="text-xs text-gray-500 mt-1">پاسخ سریع به سوالات پرتکرار مشتریان</p>
            </div>
        </div>

        <div class="divide-y divide-gray-100">
            @foreach($faqItems as [$q, $a])
            <details class="group py-4">
                <summary class="cursor-pointer list-none flex items-center justify-between gap-4 font-bold text-sm md:text-base text-gray-800">
                    <span>{{ $q }}</span>
                    <i class="fa-solid fa-chevron-down text-gray-400 group-open:rotate-180 transition-transform"></i>
                </summary>
                <p class="text-xs md:text-sm text-gray-600 leading-8 mt-3 pr-1">{{ $a }}</p>
            </details>
            @endforeach
        </div>
    </div>
</div>
@endsection
