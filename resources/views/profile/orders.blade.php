@extends('layouts.app')
@section('title', 'گیم‌پک | سفارش‌های من')
@section('content')
<div class="max-w-[900px] mx-auto px-4 py-6 md:py-8">
    <h1 class="text-xl font-black text-gray-800 mb-6">{{ setting('profile.orders_section_title', 'سفارش‌های من') }}</h1>

    <!-- Tabs -->
    <div class="flex gap-2 mb-6 border-b border-gray-200">
        @foreach(['active' => 'جاری', 'delivered' => 'تحویل شده', 'cancelled' => 'لغو/مرجوع'] as $tabKey => $tabLabel)
        <a href="{{ route('orders.index', ['tab' => $tabKey]) }}"
           class="pb-3 px-4 text-sm font-bold border-b-2 transition-colors {{ $tab === $tabKey ? 'border-brandBlue text-brandBlue' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            {{ $tabLabel }}
        </a>
        @endforeach
    </div>

    @if($orders->isEmpty())
    <div class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
        <i class="fa-solid fa-bag-shopping text-5xl text-gray-200 mb-4"></i>
        <p class="text-gray-500 text-sm">{{ setting('profile.empty_order_message', 'سفارشی در این بخش وجود ندارد.') }}</p>
    </div>
    @else
    <div class="flex flex-col gap-4">
        @foreach($orders as $order)
        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <span class="font-bold text-gray-800">{{ $order->order_number }}</span>
                    <p class="text-xs text-gray-400 mt-0.5">{{ $order->created_at->diffForHumans() }}</p>
                </div>
                <span class="text-xs px-3 py-1 rounded-full font-bold
                    {{ $order->status === 'delivered' ? 'bg-green-100 text-green-600' :
                       ($order->status === 'pending_payment' ? 'bg-yellow-100 text-yellow-600' :
                       ($order->status === 'cancelled' ? 'bg-red-100 text-red-500' : 'bg-blue-100 text-brandBlue')) }}">
                    {{ $order->status_label }}
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm font-bold text-gray-800">{{ persian_number($order->total) }} <span class="text-xs font-normal text-gray-500">تومان</span></span>
                <a href="{{ route('orders.show', $order->order_number) }}" class="text-xs text-brandBlue font-bold hover:underline">مشاهده جزئیات <i class="fa-solid fa-arrow-left text-[10px]"></i></a>
            </div>
            @if(true)
            <div class="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-600 leading-7">
                <span class="font-bold text-gray-800">آدرس گیرنده:</span>
                {{ $order->receiver_address_text }}
            </div>
            @endif
        </div>
        @endforeach
    </div>
    <div class="mt-6">{{ $orders->links() }}</div>
    @endif
</div>
@endsection

