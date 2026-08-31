@extends('admin.layouts.app')

@section('title', 'سفارش‌ها')
@section('page_badge', 'سفارش‌ها')
@section('breadcrumb') <span class="text-gray-700 text-sm">سفارش‌ها</span> @endsection

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
    'yellow' => 'text-yellow-700 bg-yellow-50',
    'blue'   => 'text-blue-700 bg-blue-50',
    'indigo' => 'text-indigo-700 bg-indigo-50',
    'purple' => 'text-purple-700 bg-purple-50',
    'green'  => 'text-green-700 bg-green-50',
    'red'    => 'text-red-700 bg-red-50',
    'orange' => 'text-orange-700 bg-orange-50',
    'gray'   => 'text-gray-500 bg-gray-100',
];
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">مدیریت سفارش‌ها</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ $orders->total() }} سفارش</p>
    </div>
</div>

{{-- Status summary chips --}}
<div class="flex flex-wrap gap-2 mb-5">
    <a href="{{ route('admin.orders.index', request()->except('status')) }}"
       class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full font-medium border transition-colors {{ !request('status') ? 'bg-brandBlue text-white border-brandBlue' : 'bg-white text-gray-600 border-gray-200 hover:border-brandBlue hover:text-brandBlue' }}">
        همه
        <span class="font-bold">{{ $orders->total() }}</span>
    </a>
    @foreach($statusLabels as $key => $meta)
    @if(($statusCounts[$key] ?? 0) > 0)
    <a href="{{ route('admin.orders.index', array_merge(request()->except('status', 'page'), ['status' => $key])) }}"
       class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full font-medium border transition-colors {{ request('status') === $key ? 'bg-brandBlue text-white border-brandBlue' : 'bg-white text-gray-600 border-gray-200 hover:border-brandBlue hover:text-brandBlue' }}">
        {{ $meta['label'] }}
        <span class="font-bold">{{ $statusCounts[$key] ?? 0 }}</span>
    </a>
    @endif
    @endforeach
</div>

{{-- Filters --}}
<form method="GET" action="{{ route('admin.orders.index') }}" class="bg-white rounded-2xl border border-gray-200 p-4 mb-5 shadow-sm">
    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-medium text-gray-600 mb-1.5">جستجو</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="شماره سفارش، نام یا موبایل..."
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1.5">وضعیت</label>
            <select name="status" class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue">
                <option value="">همه وضعیت‌ها</option>
                @foreach($statusLabels as $key => $meta)
                <option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1.5">از تاریخ</label>
            <input type="date" name="from" value="{{ request('from') }}"
                   class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue" dir="ltr">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1.5">تا تاریخ</label>
            <input type="date" name="to" value="{{ request('to') }}"
                   class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue" dir="ltr">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="bg-brandBlue text-white text-sm font-bold px-4 py-2 rounded-xl hover:bg-blue-700 transition-colors">
                <i class="fa-solid fa-search ml-1"></i> جستجو
            </button>
            @if(request()->hasAny(['search', 'status', 'from', 'to']))
            <a href="{{ route('admin.orders.index') }}" class="text-sm text-gray-500 border border-gray-200 px-3 py-2 rounded-xl hover:bg-gray-50 transition-colors">
                <i class="fa-solid fa-times"></i>
            </a>
            @endif
        </div>
    </div>
</form>

{{-- Table --}}
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
    @if($orders->isEmpty())
    <div class="py-16 text-center text-gray-400">
        <i class="fa-solid fa-bag-shopping text-4xl mb-3"></i>
        <p class="text-sm">سفارشی یافت نشد.</p>
    </div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">شماره سفارش</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">کاربر</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">مبلغ (تومان)</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">وضعیت</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">نوع تحویل</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">تاریخ</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs text-left">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($orders as $order)
                @php
                    $sm = $statusLabels[$order->status] ?? ['label' => $order->status, 'color' => 'gray'];
                    $cls = $colorMap[$sm['color']] ?? 'text-gray-500 bg-gray-100';
                @endphp
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.orders.show', $order) }}"
                           class="font-mono text-xs font-bold text-brandBlue hover:underline" dir="ltr">
                            {{ $order->order_number }}
                        </a>
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        @if($order->user)
                        <p class="text-sm font-medium text-gray-800">{{ $order->user->full_name }}</p>
                        <p class="text-[11px] text-gray-400" dir="ltr">{{ $order->user->mobile }}</p>
                        @else
                        <span class="text-gray-400 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-bold text-gray-800">{{ number_format($order->total) }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center text-[10px] font-medium px-2 py-1 rounded-full {{ $cls }}">
                            {{ $sm['label'] }}
                        </span>
                    </td>
                    <td class="px-4 py-3 hidden lg:table-cell">
                    </td>
                    <td class="px-4 py-3 hidden lg:table-cell">
                        <span class="text-xs text-gray-500">{{ $order->created_at->format('Y/m/d') }}</span>
                    </td>
                    <td class="px-4 py-3 text-left">
                        <a href="{{ route('admin.orders.show', $order) }}"
                           class="inline-flex items-center gap-1 text-xs text-brandBlue hover:underline font-medium">
                            <i class="fa-solid fa-eye text-[10px]"></i> مشاهده
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($orders->hasPages())
    <div class="px-4 py-3 border-t border-gray-100">
        {{ $orders->links() }}
    </div>
    @endif
    @endif
</div>

@endsection
