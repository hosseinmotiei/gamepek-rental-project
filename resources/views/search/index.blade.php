@extends('layouts.app')

@section('title', 'گیم‌پک | نتایج جستجو: ' . $q)

@section('content')
<div class="max-w-[1400px] mx-auto px-4 py-6 md:py-8">

    <div class="mb-6">
        <h1 class="text-lg font-black text-gray-800">
            نتایج جستجو برای
            <span class="text-brandBlue">«{{ $q }}»</span>
        </h1>
        @if($q && $products->count() > 0)
        <p class="text-sm text-gray-500 mt-1">{{ persian_number($products->total()) }} محصول یافت شد</p>
        @endif
    </div>

    @if(!$q || strlen($q) < 2)
    <div class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
        <i class="fa-solid fa-magnifying-glass text-5xl text-gray-200 mb-4"></i>
        <p class="text-gray-500 text-sm">عبارت جستجو را وارد کنید.</p>
    </div>
    @elseif($products->isEmpty())
    <div class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
        <i class="fa-solid fa-box-open text-5xl text-gray-200 mb-4"></i>
        <p class="text-gray-700 font-bold mb-2">محصولی یافت نشد</p>
        <p class="text-gray-500 text-sm mb-6">هیچ محصولی با عبارت «{{ $q }}» پیدا نشد. عبارت دیگری امتحان کنید.</p>
        <a href="{{ route('products.index') }}" class="bg-brandBlue text-white font-bold py-2.5 px-6 rounded-xl hover:bg-blue-600 transition-colors text-sm inline-block">همه محصولات</a>
    </div>
    @else
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3 md:gap-4">
        @foreach($products as $product)
        @include('partials.product-card', compact('product'))
        @endforeach
    </div>
    @if($products->hasPages())
    <div class="mt-8">{{ $products->appends(['q' => $q])->links() }}</div>
    @endif
    @endif

</div>
@endsection
