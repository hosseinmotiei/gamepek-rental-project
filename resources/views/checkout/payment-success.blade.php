@extends('layouts.app')
@section('title', 'گیم‌پک | پرداخت موفق')
@section('content')
<div class="max-w-[600px] mx-auto px-4 py-12">
    <div class="bg-white rounded-2xl p-8 shadow-sm border border-gray-100 text-center">
        <div class="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-6">
            <i class="fa-solid fa-circle-check text-green-500 text-4xl"></i>
        </div>
        <h2 class="text-xl font-black text-gray-800 mb-2">پرداخت موفق!</h2>
        <p class="text-sm text-gray-500 mb-6">سفارش شما با موفقیت ثبت شد.</p>

        <div class="bg-gray-50 rounded-xl p-4 mb-6 text-right flex flex-col gap-2">
            <div class="flex justify-between items-center text-sm">
                <span class="text-gray-500">شماره سفارش</span>
                <span class="font-bold text-gray-800">{{ persian_digits($order->order_number) }}</span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-gray-500">کد رهگیری</span>
                <span class="font-bold text-gray-800" dir="ltr">{{ $tracking_code }}</span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-gray-500">مبلغ پرداختی</span>
                <span class="font-bold text-brandDark">{{ persian_number($order->total) }} <span class="text-xs font-normal text-gray-500">تومان</span></span>
            </div>
        </div>

        <div class="flex gap-3 justify-center">
            <a href="{{ route('orders.show', $order->order_number) }}" class="bg-brandBlue text-white font-bold py-3 px-6 rounded-xl hover:bg-blue-600 transition-colors text-sm">مشاهده سفارش</a>
            <a href="{{ route('home') }}" class="border border-gray-200 text-gray-700 font-bold py-3 px-6 rounded-xl hover:bg-gray-50 transition-colors text-sm">بازگشت به فروشگاه</a>
        </div>
    </div>
</div>
<script>
try {
    ['cart', 'gamepek_cart', 'gamepekCart', 'guest_cart', 'cart_items', 'cartCount'].forEach(key => localStorage.removeItem(key));
    document.querySelectorAll('[data-cart-count]').forEach(el => el.textContent = '۰');
} catch(e) {}
</script>
@endsection
