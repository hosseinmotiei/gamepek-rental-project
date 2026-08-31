@extends('layouts.app')
@section('title', 'گیم‌پک | صفحه پیدا نشد')
@section('content')
<div class="max-w-[600px] mx-auto px-4 py-20 text-center">
    <p class="text-8xl font-black text-gray-100 mb-2">404</p>
    <h1 class="text-2xl font-black text-gray-800 mb-3">صفحه پیدا نشد</h1>
    <p class="text-sm text-gray-500 mb-8">صفحه‌ای که دنبالش می‌گردید وجود ندارد یا حذف شده است.</p>
    <div class="flex gap-3 justify-center">
        <a href="{{ route('home') }}" class="bg-brandBlue text-white font-bold py-3 px-6 rounded-xl hover:bg-blue-600 transition-colors text-sm">بازگشت به خانه</a>
        <a href="{{ route('products.index') }}" class="border border-gray-200 text-gray-700 font-bold py-3 px-6 rounded-xl hover:bg-gray-50 transition-colors text-sm">مشاهده محصولات</a>
    </div>
</div>
@endsection
