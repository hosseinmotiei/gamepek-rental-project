@extends('layouts.app')

@php
    // A search can be by text, by rental window, or both — the heading has to
    // say which one actually ran.
    $hasWindow = $from && $to;
    $hasQuery = $q && strlen($q) >= 2;
    $windowLabel = $hasWindow
        ? \App\Support\Rental\Jalali::formatLong($from).' تا '.\App\Support\Rental\Jalali::formatLong($to)
        : null;
@endphp

@section('title', 'گیم‌پک | ' . ($hasQuery ? 'نتایج جستجو: ' . $q : 'جستجوی اجاره'))

@section('content')
<div class="max-w-[1400px] mx-auto px-4 py-6 md:py-8">

    {{-- Refine without going back to the home page — same component. --}}
    <div class="mb-6">
        @include('partials.rental-search-bar', ['variant' => 'compact'])
    </div>

    @foreach($searchErrors as $message)
        <p class="mb-4 bg-red-50 border border-red-100 text-red-700 text-sm rounded-xl px-4 py-3">{{ $message }}</p>
    @endforeach

    <div class="mb-6">
        <h1 class="text-lg font-black text-gray-800">
            @if($hasQuery)
                نتایج جستجو برای <span class="text-brandBlue">«{{ $q }}»</span>
            @elseif($hasWindow)
                موارد قابل اجاره در بازه انتخابی
            @else
                جستجوی اجاره
            @endif
        </h1>

        @if($hasWindow || $city)
        <div class="flex flex-wrap items-center gap-2 mt-3">
            @if($city)
            <span class="inline-flex items-center gap-1.5 bg-gray-100 text-gray-700 text-xs font-medium px-3 py-1.5 rounded-full">
                <i class="fa-solid fa-location-dot text-brandBlue"></i> {{ $city }}
            </span>
            @endif
            @if($hasWindow)
            <span class="inline-flex items-center gap-1.5 bg-gray-100 text-gray-700 text-xs font-medium px-3 py-1.5 rounded-full">
                <i class="fa-regular fa-calendar text-brandBlue"></i> {{ $windowLabel }}
            </span>
            <span class="inline-flex items-center gap-1.5 bg-gray-100 text-gray-700 text-xs font-medium px-3 py-1.5 rounded-full">
                <i class="fa-regular fa-clock text-brandBlue"></i> {{ persian_number($days) }} روز
            </span>
            @endif
        </div>
        @endif

        @if(($hasQuery || $hasWindow) && $products->count() > 0)
        <p class="text-sm text-gray-500 mt-3">{{ persian_number($products->total()) }} مورد یافت شد</p>
        @endif
    </div>

    @if(! $hasQuery && ! $hasWindow)
    <div class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
        <i class="fa-solid fa-magnifying-glass text-5xl text-gray-200 mb-4"></i>
        <p class="text-gray-700 font-bold mb-2">جستجو را شروع کنید</p>
        <p class="text-gray-500 text-sm">شهر و بازه تاریخ اجاره را از نوار بالا انتخاب کنید، یا یک عبارت جستجو وارد کنید.</p>
    </div>
    @elseif($products->isEmpty())
    <div class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
        <i class="fa-solid fa-box-open text-5xl text-gray-200 mb-4"></i>
        <p class="text-gray-700 font-bold mb-2">موردی یافت نشد</p>
        <p class="text-gray-500 text-sm mb-6">
            @if($hasWindow)
                در بازه {{ $windowLabel }} دستگاه آزادی پیدا نشد. بازه دیگری را امتحان کنید.
            @else
                هیچ موردی با عبارت «{{ $q }}» پیدا نشد. عبارت دیگری امتحان کنید.
            @endif
        </p>
        <a href="{{ route('products.index') }}" class="bg-brandBlue text-white font-bold py-2.5 px-6 rounded-xl hover:bg-blue-600 transition-colors text-sm inline-block">همه موارد</a>
    </div>
    @else
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3 md:gap-4">
        @foreach($products as $product)
        @include('partials.product-card', compact('product'))
        @endforeach
    </div>
    @if($products->hasPages())
    {{-- appends() keeps the whole search (text + city + window) on every page. --}}
    <div class="mt-8">{{ $products->appends(request()->only('q', 'city', 'from', 'to', 'sort'))->links() }}</div>
    @endif
    @endif

</div>
@endsection
