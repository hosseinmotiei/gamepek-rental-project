@extends('admin.layouts.app')
@section('title', 'گزارش فروش')

@section('content')
<div class="space-y-6">

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">گزارش فروش</h1>
      <p class="text-sm text-gray-500 mt-1">تحلیل درآمد و سفارشات پرداخت‌شده</p>
    </div>
    <div class="flex gap-2">
      @can('export_reports')
      <a href="{{ route('admin.reports.sales.export', request()->query()) }}" class="inline-flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700">
        <i class="fas fa-download"></i> خروجی CSV
      </a>
      @endcan
      <a href="{{ route('admin.reports.index') }}" class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">
        <i class="fas fa-arrow-right"></i> بازگشت
      </a>
    </div>
  </div>

  {{-- Filter form --}}
  <div class="bg-white rounded-xl shadow p-4">
    <form method="GET" action="{{ route('admin.reports.sales') }}" class="flex flex-wrap gap-3 items-end">
      <div>
        <label class="block text-xs text-gray-600 mb-1">از تاریخ</label>
        <input type="date" name="date_from" value="{{ $dateFrom }}" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-1">تا تاریخ</label>
        <input type="date" name="date_to" value="{{ $dateTo }}" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
      </div>
      <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm hover:bg-blue-700">اعمال فیلتر</button>
      <a href="{{ route('admin.reports.sales') }}" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">ریست</a>
    </form>
  </div>

  {{-- Summary cards --}}
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="bg-white rounded-xl shadow p-5">
      <p class="text-xs text-gray-500 mb-1">درآمد دوره انتخابی</p>
      <p class="text-2xl font-bold text-green-600">{{ number_format($summary['total_revenue']) }}</p>
      <p class="text-xs text-gray-400 mt-1">تومان</p>
    </div>
    <div class="bg-white rounded-xl shadow p-5">
      <p class="text-xs text-gray-500 mb-1">تعداد سفارشات</p>
      <p class="text-2xl font-bold text-blue-600">{{ number_format($summary['total_orders']) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow p-5">
      <p class="text-xs text-gray-500 mb-1">میانگین هر سفارش</p>
      <p class="text-2xl font-bold text-purple-600">{{ number_format($summary['avg_order_value']) }}</p>
      <p class="text-xs text-gray-400 mt-1">تومان</p>
    </div>
    <div class="bg-white rounded-xl shadow p-5">
      <p class="text-xs text-gray-500 mb-1">مبلغ مسترد شده</p>
      <p class="text-2xl font-bold text-red-600">{{ number_format($summary['refunded_amount']) }}</p>
      <p class="text-xs text-gray-400 mt-1">تومان</p>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- By delivery type --}}
    <div class="bg-white rounded-xl shadow">
      <div class="p-4 border-b"><h2 class="font-semibold text-gray-900">تفکیک بر اساس نوع تحویل</h2></div>
      <div class="p-4">
        @forelse($byDelivery as $row)
        <div class="flex items-center justify-between py-2 border-b last:border-0">
          <div class="text-left">
            <span class="text-sm font-bold text-gray-900">{{ number_format($row->revenue) }} تومان</span>
            <span class="text-xs text-gray-400 mr-2">({{ $row->count }} سفارش)</span>
          </div>
        </div>
        @empty
        <p class="text-center text-sm text-gray-400 py-4">داده‌ای موجود نیست</p>
        @endforelse
      </div>
    </div>

    {{-- By gateway --}}
    <div class="bg-white rounded-xl shadow">
      <div class="p-4 border-b"><h2 class="font-semibold text-gray-900">تفکیک بر اساس درگاه پرداخت</h2></div>
      <div class="p-4">
        @forelse($byGateway as $row)
        <div class="flex items-center justify-between py-2 border-b last:border-0">
          <span class="text-sm text-gray-700">{{ $row->gateway }}</span>
          <div class="text-left">
            <span class="text-sm font-bold text-gray-900">{{ number_format($row->revenue) }} تومان</span>
            <span class="text-xs text-gray-400 mr-2">({{ $row->count }} تراکنش)</span>
          </div>
        </div>
        @empty
        <p class="text-center text-sm text-gray-400 py-4">داده‌ای موجود نیست</p>
        @endforelse
      </div>
    </div>
  </div>

  {{-- Daily sales table --}}
  <div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b"><h2 class="font-semibold text-gray-900">فروش روزانه</h2></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr>
            <th class="px-4 py-3 text-right">تاریخ</th>
            <th class="px-4 py-3 text-right">تعداد سفارش</th>
            <th class="px-4 py-3 text-right">درآمد (تومان)</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($dailySales as $row)
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3">{{ $row->date }}</td>
            <td class="px-4 py-3">{{ $row->order_count }}</td>
            <td class="px-4 py-3 font-bold text-green-700">{{ number_format($row->revenue) }}</td>
          </tr>
          @empty
          <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">داده‌ای موجود نیست</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
