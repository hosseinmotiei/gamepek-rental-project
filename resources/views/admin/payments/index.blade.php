@extends('admin.layouts.app')

@section('title', 'پرداخت‌ها')
@section('page_badge', 'پرداخت‌ها')
@section('breadcrumb') <span class="text-gray-700 text-sm">پرداخت‌ها</span> @endsection

@section('content')

@php
$statusLabels = [
    'pending'   => ['label' => 'در انتظار', 'cls' => 'text-yellow-700 bg-yellow-50'],
    'success'   => ['label' => 'موفق',      'cls' => 'text-green-700 bg-green-50'],
    'failed'    => ['label' => 'ناموفق',    'cls' => 'text-red-700 bg-red-50'],
    'cancelled' => ['label' => 'لغوشده',   'cls' => 'text-gray-500 bg-gray-100'],
    'refunded'  => ['label' => 'مسترد',    'cls' => 'text-orange-700 bg-orange-50'],
];
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">مدیریت پرداخت‌ها</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ $transactions->total() }} تراکنش</p>
    </div>
</div>

{{-- Filters --}}
<form method="GET" action="{{ route('admin.payments.index') }}" class="bg-white rounded-2xl border border-gray-200 p-4 mb-5 shadow-sm">
    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-medium text-gray-600 mb-1.5">جستجو</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="کد پیگیری، شماره سفارش، نام یا موبایل..."
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1.5">وضعیت</label>
            <select name="status" class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue">
                <option value="">همه</option>
                @foreach($statusLabels as $key => $meta)
                <option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1.5">درگاه</label>
            <select name="gateway" class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue">
                <option value="">همه درگاه‌ها</option>
                <option value="mock"      {{ request('gateway') === 'mock'      ? 'selected' : '' }}>Mock (تست)</option>
                <option value="zarinpal"  {{ request('gateway') === 'zarinpal'  ? 'selected' : '' }}>زرین‌پال</option>
                <option value="idpay"     {{ request('gateway') === 'idpay'     ? 'selected' : '' }}>آی‌دی‌پی</option>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="bg-brandBlue text-white text-sm font-bold px-4 py-2 rounded-xl hover:bg-blue-700 transition-colors">
                <i class="fa-solid fa-search ml-1"></i> جستجو
            </button>
            @if(request()->hasAny(['search', 'status', 'gateway']))
            <a aria-label="حذف فیلترها" title="حذف فیلترها" href="{{ route('admin.payments.index') }}" class="text-sm text-gray-500 border border-gray-200 px-3 py-2 rounded-xl hover:bg-gray-50 transition-colors">
                <i class="fa-solid fa-times"></i>
            </a>
            @endif
        </div>
    </div>
</form>

{{-- Table --}}
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
    @if($transactions->isEmpty())
    <div class="py-16 text-center text-gray-400">
        <i class="fa-solid fa-credit-card text-4xl mb-3"></i>
        <p class="text-sm">تراکنشی یافت نشد.</p>
    </div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">کد پیگیری</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">شماره سفارش</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">کاربر</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">مبلغ</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">درگاه</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">وضعیت</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">تاریخ</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs text-left">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($transactions as $tx)
                @php $meta = $statusLabels[$tx->status] ?? ['label' => $tx->status, 'cls' => 'text-gray-500 bg-gray-100']; @endphp
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3">
                        @if($tx->tracking_code)
                        <span class="font-mono text-xs text-gray-800" dir="ltr">{{ $tx->tracking_code }}</span>
                        @elseif($tx->authority)
                        <span class="font-mono text-[10px] text-gray-400" dir="ltr">{{ Str::limit($tx->authority, 20) }}</span>
                        @else
                        <span class="text-gray-400 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        @if($tx->order)
                        <a href="{{ route('admin.orders.show', $tx->order) }}"
                           class="font-mono text-xs text-brandBlue hover:underline" dir="ltr">
                            {{ $tx->order->order_number }}
                        </a>
                        @else
                        <span class="text-gray-400 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        @if($tx->user)
                        <p class="text-sm font-medium text-gray-800">{{ $tx->user->full_name }}</p>
                        <p class="text-[11px] text-gray-400" dir="ltr">{{ $tx->user->mobile }}</p>
                        @else
                        <span class="text-gray-400 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-bold text-gray-800">{{ number_format($tx->amount) }} <small class="text-gray-400 font-normal text-xs">ت</small></td>
                    <td class="px-4 py-3 hidden lg:table-cell">
                        <span class="text-xs font-mono uppercase text-gray-500">{{ $tx->gateway }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center text-[10px] font-medium px-2 py-1 rounded-full {{ $meta['cls'] }}">
                            {{ $meta['label'] }}
                        </span>
                    </td>
                    <td class="px-4 py-3 hidden lg:table-cell">
                        <span class="text-xs text-gray-500">{{ $tx->created_at->format('Y/m/d H:i') }}</span>
                    </td>
                    <td class="px-4 py-3 text-left">
                        <a href="{{ route('admin.payments.show', $tx) }}"
                           class="inline-flex items-center gap-1 text-xs text-brandBlue hover:underline font-medium">
                            <i class="fa-solid fa-eye text-[10px]"></i> جزئیات
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($transactions->hasPages())
    <div class="px-4 py-3 border-t border-gray-100">
        {{ $transactions->links() }}
    </div>
    @endif
    @endif
</div>

@endsection
