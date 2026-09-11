@extends('layouts.app')
@section('title', 'گیم‌پک | جزئیات سفارش')
@section('content')
<div class="max-w-[900px] mx-auto px-4 py-6 md:py-8">
    <div class="flex items-center gap-3 mb-6">
        <a aria-label="بازگشت به سفارش‌ها" href="{{ route('orders.index') }}" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-right"></i></a>
        <h1 class="text-xl font-black text-gray-800">سفارش {{ $order->order_number }}</h1>
        <span class="text-xs px-3 py-1 rounded-full font-bold
            {{ $order->status === 'delivered' ? 'bg-green-100 text-green-600' :
               ($order->status === 'pending_payment' ? 'bg-yellow-100 text-yellow-600' :
               ($order->status === 'cancelled' ? 'bg-red-100 text-red-500' : 'bg-blue-100 text-brandBlue')) }}">
            {{ $order->status_label }}
        </span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <!-- Items -->
        <div class="md:col-span-2">
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                <h3 class="font-bold text-gray-800 mb-4">کالاهای سفارش</h3>
                <div class="flex flex-col gap-4">
                    @foreach($order->items as $item)
                    <div class="flex gap-4 pb-4 border-b border-gray-50 last:border-0 last:pb-0">
                        <img src="{{ media_url($item->product_image_snapshot, 'https://placehold.co/80x80/F5F5F5/111?text=P') }}" alt=""
                             class="w-16 h-16 object-contain bg-gray-50 rounded-xl shrink-0">
                        <div class="flex-1">
                            <p class="font-bold text-sm text-gray-800">{{ $item->product_title_snapshot }}</p>
                            @if(!empty($item->selected_options))
                            <div class="flex flex-wrap gap-1 mt-1">
                                @foreach($item->selected_options as $groupTitle => $valueLabel)
                                <span class="text-[10px] bg-blue-50 text-brandBlue font-medium px-1.5 py-0.5 rounded-lg">{{ $groupTitle }}: {{ $valueLabel }}</span>
                                @endforeach
                            </div>
                            @endif
                            <p class="text-xs text-gray-500 mt-1">تعداد: {{ $item->quantity }} | واحد: {{ persian_number($item->sale_price ?? $item->unit_price) }} تومان</p>

                        </div>
                        <div class="text-left shrink-0">
                            <span class="font-bold text-sm">{{ persian_number($item->total_price) }}</span>
                            <span class="text-xs text-gray-500"> ت</span>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Summary -->
        <div class="flex flex-col gap-4">
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                <h3 class="font-bold text-gray-800 mb-4">خلاصه مالی</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between text-gray-600"><span>جمع</span><span>{{ persian_number($order->subtotal) }} ت</span></div>
                    @if($order->discount_total > 0)<div class="flex justify-between text-green-600"><span>تخفیف</span><span>- {{ persian_number($order->discount_total) }} ت</span></div>@endif
                    @if($order->shipping_cost > 0)<div class="flex justify-between text-gray-600"><span>ارسال</span><span>{{ persian_number($order->shipping_cost) }} ت</span></div>@endif
                    <div class="flex justify-between font-bold text-gray-800 border-t border-gray-100 pt-2"><span>کل</span><span>{{ persian_number($order->total) }} ت</span></div>
                </div>
            </div>

            @if(true)
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                <h3 class="font-bold text-gray-800 mb-3 text-sm">آدرس تحویل</h3>
                <p class="text-xs text-gray-800 font-bold">{{ $order->receiver_name ?? '-' }}</p>
                <p class="text-xs text-gray-600 mt-1 leading-relaxed">{{ $order->receiver_address_text }}</p>
                @if($order->receiver_mobile)
                <p class="text-xs text-gray-500 mt-2" dir="ltr">{{ $order->receiver_mobile }}</p>
                @endif
                <p class="text-xs text-gray-500 mt-3"><i class="fa-solid fa-shop text-brandBlue ml-1"></i> مبدأ ارسال: تهران، سعادت‌آباد</p>
            </div>
            @endif

            @if($order->latestTransaction)
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                <h3 class="font-bold text-gray-800 mb-3 text-sm">اطلاعات پرداخت</h3>
                <p class="text-xs text-gray-600">کد پیگیری: <span class="font-bold text-gray-800">{{ $order->latestTransaction->tracking_code ?? '-' }}</span></p>
                <p class="text-xs text-gray-600 mt-1">درگاه: <span class="font-bold">{{ $order->latestTransaction->gateway }}</span></p>
            </div>
            @endif

            @if(in_array($order->status, ['pending_payment', 'paid', 'processing']))
            <button onclick="cancelOrder('{{ $order->order_number }}')" class="w-full border border-red-200 text-red-500 font-bold py-2.5 rounded-xl hover:bg-red-50 transition-colors text-sm">
                لغو سفارش
            </button>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
</script>
@endpush

