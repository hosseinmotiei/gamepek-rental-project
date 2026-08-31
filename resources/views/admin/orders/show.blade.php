@extends('admin.layouts.app')

@section('title', $order->order_number)
@section('page_badge', 'جزئیات سفارش')
@section('breadcrumb')
    <a href="{{ route('admin.orders.index') }}" class="hover:text-brandBlue transition-colors text-sm">سفارش‌ها</a>
    <i class="fa-solid fa-chevron-left text-[10px] mx-1"></i>
    <span class="text-gray-700 text-sm font-mono" dir="ltr">{{ $order->order_number }}</span>
@endsection

@section('content')

@php
$statusLabels = [
    'pending_payment' => ['label' => 'در انتظار پرداخت', 'color' => 'yellow'],
    'paid'            => ['label' => 'پرداخت شده',       'color' => 'blue'],
    'processing'      => ['label' => 'در حال پردازش',    'color' => 'indigo'],
    'shipped'         => ['label' => 'ارسال شده',        'color' => 'purple'],
    'delivered'       => ['label' => 'تحویل داده شده',   'color' => 'green'],
    'cancelled'       => ['label' => 'لغو شده',          'color' => 'red'],
    'refunded'        => ['label' => 'مسترد شده',        'color' => 'orange'],
    'failed'          => ['label' => 'ناموفق',           'color' => 'gray'],
];
$colorMap = [
    'yellow' => 'text-yellow-700 bg-yellow-50 border-yellow-200',
    'blue'   => 'text-blue-700 bg-blue-50 border-blue-200',
    'indigo' => 'text-indigo-700 bg-indigo-50 border-indigo-200',
    'purple' => 'text-purple-700 bg-purple-50 border-purple-200',
    'green'  => 'text-green-700 bg-green-50 border-green-200',
    'red'    => 'text-red-700 bg-red-50 border-red-200',
    'orange' => 'text-orange-700 bg-orange-50 border-orange-200',
    'gray'   => 'text-gray-500 bg-gray-100 border-gray-200',
];
$sm = $statusLabels[$order->status] ?? ['label' => $order->status, 'color' => 'gray'];
$cls = $colorMap[$sm['color']] ?? 'text-gray-500 bg-gray-100 border-gray-200';
@endphp

{{-- Action bar --}}
<div class="flex flex-wrap items-center gap-3 mb-6">
    @can('update_order_status')
    <form method="POST" action="{{ route('admin.orders.status', $order) }}" class="flex items-center gap-2">
        @csrf @method('PATCH')
        <select name="status" class="bg-white border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue">
            @foreach($statusLabels as $key => $meta)
            <option value="{{ $key }}" {{ $order->status === $key ? 'selected' : '' }}>{{ $meta['label'] }}</option>
            @endforeach
        </select>
        <button type="submit" class="bg-brandBlue text-white text-sm font-bold px-4 py-2 rounded-xl hover:bg-blue-700 transition-colors">
            <i class="fa-solid fa-check ml-1"></i> ثبت وضعیت
        </button>
    </form>
    @endcan

    @can('print_invoice')
    <button onclick="window.print()"
            class="inline-flex items-center gap-2 text-sm text-gray-600 border border-gray-200 px-4 py-2 rounded-xl hover:border-brandBlue hover:text-brandBlue transition-all">
        <i class="fa-solid fa-print text-xs"></i> چاپ فاکتور
    </button>
    @endcan
</div>

<div class="flex flex-col xl:flex-row gap-6">

    {{-- Left column --}}
    <div class="flex-1 flex flex-col gap-5">

        {{-- Items --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h2 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
                <i class="fa-solid fa-box text-brandBlue text-xs"></i> اقلام سفارش
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-right">
                            <th class="pb-2 font-semibold text-gray-500 text-xs">محصول</th>
                            <th class="pb-2 font-semibold text-gray-500 text-xs text-center">تعداد</th>
                            <th class="pb-2 font-semibold text-gray-500 text-xs text-left">قیمت واحد</th>
                            <th class="pb-2 font-semibold text-gray-500 text-xs text-left">جمع</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($order->items as $item)
                        <tr>
                            <td class="py-3 pl-4">
                                <div class="flex items-center gap-3">
                                    @if($item->product_image_snapshot)
                                    <img src="{{ media_url($item->product_image_snapshot) }}" alt=""
                                         class="w-10 h-10 object-contain rounded-lg border border-gray-100 shrink-0">
                                    @else
                                    <div class="w-10 h-10 bg-gray-100 rounded-lg shrink-0 flex items-center justify-center">
                                        <i class="fa-solid fa-box text-gray-300 text-xs"></i>
                                    </div>
                                    @endif
                                    <div>
                                        <p class="font-medium text-gray-800 text-sm">{{ $item->product_title_snapshot }}</p>
                                        @if($item->product_sku_snapshot)
                                        <p class="text-[10px] text-gray-400 font-mono" dir="ltr">{{ $item->product_sku_snapshot }}</p>
                                        @endif
                                        @if(!empty($item->selected_options))
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            @foreach($item->selected_options as $groupTitle => $valueLabel)
                                            <span class="text-[9px] bg-blue-50 text-brandBlue font-medium px-1.5 py-0.5 rounded">{{ $groupTitle }}: {{ $valueLabel }}</span>
                                            @endforeach
                                        </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 text-center font-bold text-gray-800">{{ $item->quantity }}</td>
                            <td class="py-3 text-left text-gray-600">
                                @if($item->sale_price && $item->sale_price < $item->unit_price)
                                    <span class="line-through text-gray-300 text-xs">{{ number_format($item->unit_price) }}</span>
                                    <span class="font-medium text-flashRed block">{{ number_format($item->sale_price) }}</span>
                                @else
                                    {{ number_format($item->unit_price) }}
                                @endif
                            </td>
                            <td class="py-3 text-left font-bold text-gray-800">{{ number_format($item->total_price) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>


        {{-- Customer note --}}
        @if($order->customer_note)
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h2 class="font-bold text-gray-800 text-sm mb-3 flex items-center gap-2">
                <i class="fa-solid fa-comment text-blue-400 text-xs"></i> یادداشت مشتری
            </h2>
            <p class="text-sm text-gray-600 leading-relaxed bg-blue-50 rounded-xl p-3">{{ $order->customer_note }}</p>
        </div>
        @endif

        {{-- Admin note --}}
        @can('view_order_notes')
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h2 class="font-bold text-gray-800 text-sm mb-3 flex items-center gap-2">
                <i class="fa-solid fa-note-sticky text-yellow-500 text-xs"></i> یادداشت داخلی
            </h2>
            @can('create_order_notes')
            <form method="POST" action="{{ route('admin.orders.note', $order) }}">
                @csrf
                <textarea name="admin_note" rows="3"
                          class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all resize-none mb-3"
                          placeholder="یادداشت داخلی — فقط برای تیم ادمین قابل مشاهده است...">{{ $order->admin_note }}</textarea>
                <button type="submit" class="bg-yellow-500 text-white text-sm font-bold px-4 py-2 rounded-xl hover:bg-yellow-600 transition-colors">
                    <i class="fa-solid fa-save ml-1"></i> ذخیره یادداشت
                </button>
            </form>
            @else
            <p class="text-sm text-gray-600 bg-yellow-50 rounded-xl p-3">{{ $order->admin_note ?: 'یادداشتی ثبت نشده.' }}</p>
            @endcan
        </div>
        @endcan

        {{-- Payment transactions --}}
        @if($order->paymentTransactions->isNotEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h2 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
                <i class="fa-solid fa-credit-card text-brandBlue text-xs"></i> تراکنش‌های پرداخت
            </h2>
            <div class="flex flex-col gap-2">
                @foreach($order->paymentTransactions as $tx)
                @php
                    $txColors = ['success' => 'text-green-700 bg-green-50', 'failed' => 'text-red-700 bg-red-50', 'pending' => 'text-yellow-700 bg-yellow-50', 'cancelled' => 'text-gray-500 bg-gray-100', 'refunded' => 'text-orange-700 bg-orange-50'];
                    $txLabels = ['success' => 'موفق', 'failed' => 'ناموفق', 'pending' => 'در انتظار', 'cancelled' => 'لغوشده', 'refunded' => 'مسترد'];
                @endphp
                <div class="flex items-center justify-between bg-gray-50 rounded-xl p-3 text-sm">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center text-[10px] font-medium px-2 py-0.5 rounded-full {{ $txColors[$tx->status] ?? 'text-gray-500 bg-gray-100' }}">
                            {{ $txLabels[$tx->status] ?? $tx->status }}
                        </span>
                        <span class="text-gray-600 text-xs uppercase font-mono" dir="ltr">{{ $tx->gateway }}</span>
                        @if($tx->tracking_code)
                        <span class="text-gray-500 text-xs font-mono" dir="ltr">{{ $tx->tracking_code }}</span>
                        @endif
                    </div>
                    <div class="text-left">
                        <p class="font-bold text-gray-800">{{ number_format($tx->amount) }} <small class="text-gray-400 font-normal">تومان</small></p>
                        <p class="text-[10px] text-gray-400">{{ $tx->created_at->format('Y/m/d H:i') }}</p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- Right sidebar --}}
    <div class="w-full xl:w-72 shrink-0 flex flex-col gap-5">

        {{-- Order info --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-4">اطلاعات سفارش</h3>
            <div class="flex flex-col gap-3">
                <div>
                    <p class="text-[10px] text-gray-400 mb-0.5">شماره سفارش</p>
                    <p class="font-mono font-bold text-sm text-brandBlue" dir="ltr">{{ $order->order_number }}</p>
                </div>
                <div>
                    <p class="text-[10px] text-gray-400 mb-1">وضعیت</p>
                    <span class="inline-flex items-center text-xs font-medium px-3 py-1 rounded-full border {{ $cls }}">{{ $sm['label'] }}</span>
                </div>
                <div class="h-px bg-gray-100"></div>
                @foreach([
                    ['ثبت سفارش', $order->created_at->format('Y/m/d H:i')],
                    ['پرداخت', $order->paid_at?->format('Y/m/d H:i') ?? '—'],
                    ['ارسال', $order->shipped_at?->format('Y/m/d H:i') ?? '—'],
                    ['تحویل', $order->delivered_at?->format('Y/m/d H:i') ?? '—'],
                ] as [$label, $val])
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">{{ $label }}</span>
                    <span class="text-gray-800 font-medium text-xs" dir="ltr">{{ $val }}</span>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Totals --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-4">مبالغ</h3>
            <div class="flex flex-col gap-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">جمع محصولات</span>
                    <span>{{ number_format($order->subtotal) }}</span>
                </div>
                @if($order->discount_total > 0)
                <div class="flex justify-between text-flashRed">
                    <span>تخفیف</span>
                    <span>- {{ number_format($order->discount_total) }}</span>
                </div>
                @endif
                @if($order->shipping_cost > 0)
                <div class="flex justify-between">
                    <span class="text-gray-500">هزینه ارسال</span>
                    <span>{{ number_format($order->shipping_cost) }}</span>
                </div>
                @endif
                @if($order->coupon)
                <div class="flex justify-between text-green-600 text-xs">
                    <span>کد تخفیف ({{ $order->coupon->code }})</span>
                    <span>اعمال شده</span>
                </div>
                @endif
                <div class="h-px bg-gray-100 my-1"></div>
                <div class="flex justify-between font-bold text-base">
                    <span>مجموع</span>
                    <span>{{ number_format($order->total) }} <small class="text-gray-400 font-normal text-xs">تومان</small></span>
                </div>
            </div>
        </div>

        {{-- Customer --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-4">مشتری</h3>
            @if($order->user)
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-full bg-brandBlue/10 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-user text-brandBlue text-sm"></i>
                </div>
                <div>
                    <p class="font-medium text-gray-800 text-sm">{{ $order->user->full_name }}</p>
                    <p class="text-xs text-gray-400" dir="ltr">{{ $order->user->mobile }}</p>
                </div>
            </div>
            @can('view_users')
            <a href="{{ route('admin.users.show', $order->user) }}"
               class="block text-center text-xs text-brandBlue border border-brandBlue/30 rounded-xl py-1.5 hover:bg-brandBlue hover:text-white transition-all">
                مشاهده پروفایل کاربر
            </a>
            @endcan
            @else
            <p class="text-gray-400 text-sm">کاربر حذف شده</p>
            @endif
        </div>

        {{-- Shipping address --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-3">آدرس تحویل</h3>
            <div class="text-sm text-gray-600 space-y-1">
                <p class="font-medium text-gray-800">{{ $order->receiver_name ?? '-' }}</p>
                @if($order->receiver_mobile)
                <p dir="ltr" class="text-xs text-gray-500">{{ $order->receiver_mobile }}</p>
                @endif
                <p class="text-xs leading-relaxed">{{ $order->receiver_address_text }}</p>
                <p class="text-xs text-gray-500 mt-2"><i class="fa-solid fa-shop text-blue-500 ml-1"></i> مبدأ ارسال: {{ setting('general.contact_address', 'تهران، سعادت‌آباد') }}</p>
            </div>
        </div>
    </div>
</div>

@endsection
