@extends('layouts.app')
@section('title', ($cancelled ?? false) ? 'گیم‌پک | پرداخت لغو شد' : 'گیم‌پک | پرداخت ناموفق')
@section('content')
<div class="max-w-[600px] mx-auto px-4 py-12">
    <div class="bg-white rounded-2xl p-8 shadow-sm border border-gray-100 text-center">
        <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-6">
            <i class="fa-solid fa-circle-xmark text-red-500 text-4xl"></i>
        </div>
        <h2 class="text-xl font-black text-gray-800 mb-2">{{ ($cancelled ?? false) ? 'پرداخت لغو شد' : 'پرداخت ناموفق بود' }}</h2>
        <p class="text-sm text-gray-500 mb-2">{{ ($cancelled ?? false) ? 'پرداخت توسط شما لغو شد. سبد خرید شما حفظ شده است.' : ($message ?? 'پرداخت انجام نشد. لطفاً مجدداً تلاش کنید.') }}</p>
        <p class="text-xs text-gray-400 mb-8">مبلغ از حساب شما کسر نشده است. سفارش شما همچنان در پنل منتظر پرداخت است.</p>
        <div class="flex flex-col sm:flex-row flex-wrap gap-3 justify-center">
            <a href="{{ route('cart.index') }}" class="bg-brandBlue text-white font-bold py-3 px-6 rounded-xl hover:bg-blue-600 transition-colors text-sm">بازگشت به سبد خرید</a>
            <a href="{{ route('orders.index') }}" class="border border-gray-200 text-gray-700 font-bold py-3 px-6 rounded-xl hover:bg-gray-50 transition-colors text-sm">سفارش‌های من</a>
            <a href="{{ route('home') }}" class="border border-gray-200 text-gray-700 font-bold py-3 px-6 rounded-xl hover:bg-gray-50 transition-colors text-sm">صفحه اصلی</a>
        </div>
    </div>
</div>
@endsection
