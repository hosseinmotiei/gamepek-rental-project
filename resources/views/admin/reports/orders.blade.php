@extends('admin.layouts.app')
@section('title', 'گزارش سفارشات')

@section('content')
<div class="space-y-6">

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">گزارش سفارشات</h1>
      <p class="text-sm text-gray-500 mt-1">وضعیت کلیه سفارشات فروشگاه</p>
    </div>
    <div class="flex gap-2">
      @can('export_reports')
      <a href="{{ route('admin.reports.orders.export', request()->query()) }}" class="inline-flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700">
        <i class="fas fa-download"></i> خروجی CSV
      </a>
      @endcan
      <a href="{{ route('admin.reports.index') }}" class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">
        <i class="fas fa-arrow-right"></i> بازگشت
      </a>
    </div>
  </div>

  {{-- Filters --}}
  <div class="bg-white rounded-xl shadow p-4">
    <form method="GET" action="{{ route('admin.reports.orders') }}" class="flex flex-wrap gap-3 items-end">
      <div>
        <label class="block text-xs text-gray-600 mb-1">از تاریخ</label>
        <input type="date" name="date_from" value="{{ $dateFrom }}" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-1">تا تاریخ</label>
        <input type="date" name="date_to" value="{{ $dateTo }}" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-1">وضعیت سفارش</label>
        <select name="status" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
          <option value="">همه</option>
          @foreach(['pending_payment'=>'در انتظار پرداخت','paid'=>'پرداخت شده','processing'=>'در حال پردازش','shipped'=>'ارسال شده','delivered'=>'تحویل داده شده','cancelled'=>'لغو شده','refunded'=>'مسترد شده'] as $val => $lbl)
          <option value="{{ $val }}" {{ $status === $val ? 'selected' : '' }}>{{ $lbl }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-1">وضعیت پرداخت</label>
        <select name="payment_status" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
          <option value="">همه</option>
          <option value="paid" {{ $payStatus === 'paid' ? 'selected' : '' }}>پرداخت شده</option>
          <option value="unpaid" {{ $payStatus === 'unpaid' ? 'selected' : '' }}>پرداخت نشده</option>
          <option value="refunded" {{ $payStatus === 'refunded' ? 'selected' : '' }}>مسترد شده</option>
        </select>
      </div>
      <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm hover:bg-blue-700">اعمال فیلتر</button>
      <a href="{{ route('admin.reports.orders') }}" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">ریست</a>
    </form>
  </div>

  {{-- Summary --}}
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
    @php
    $summaryCards = [
      ['label' => 'کل', 'value' => $summary['total'], 'color' => 'gray'],
      ['label' => 'پرداخت شده', 'value' => $summary['paid'], 'color' => 'green'],
      ['label' => 'در پردازش', 'value' => $summary['processing'], 'color' => 'blue'],
      ['label' => 'تحویل داده شده', 'value' => $summary['delivered'], 'color' => 'indigo'],
      ['label' => 'لغو شده', 'value' => $summary['cancelled'], 'color' => 'red'],
      ['label' => 'مسترد شده', 'value' => $summary['refunded'], 'color' => 'orange'],
    ];
    @endphp
    @foreach($summaryCards as $sc)
    <div class="bg-white rounded-xl shadow p-4 text-center">
      <div class="text-xl font-bold text-{{ $sc['color'] }}-600">{{ number_format($sc['value']) }}</div>
      <div class="text-xs text-gray-500 mt-1">{{ $sc['label'] }}</div>
    </div>
    @endforeach
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    {{-- By status --}}
    <div class="bg-white rounded-xl shadow">
      <div class="p-4 border-b"><h3 class="font-semibold text-gray-900 text-sm">بر اساس وضعیت</h3></div>
      <ul class="divide-y divide-gray-100 p-2">
        @foreach($byStatus as $row)
        <li class="flex justify-between items-center px-2 py-2">
          <span class="text-sm text-gray-700">{{ $row->status }}</span>
          <span class="text-sm font-bold text-gray-900">{{ $row->count }}</span>
        </li>
        @endforeach
      </ul>
    </div>
    {{-- By payment --}}
    <div class="bg-white rounded-xl shadow">
      <div class="p-4 border-b"><h3 class="font-semibold text-gray-900 text-sm">بر اساس پرداخت</h3></div>
      <ul class="divide-y divide-gray-100 p-2">
        @foreach($byPayment as $row)
        <li class="flex justify-between items-center px-2 py-2">
          <span class="text-sm text-gray-700">{{ $row->payment_status }}</span>
          <span class="text-sm font-bold text-gray-900">{{ $row->count }}</span>
        </li>
        @endforeach
      </ul>
    </div>
    {{-- By delivery --}}
    <div class="bg-white rounded-xl shadow">
  </div>

  {{-- Orders table --}}
  <div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b"><h2 class="font-semibold text-gray-900">لیست سفارشات</h2></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr>
            <th class="px-4 py-3 text-right">شماره</th>
            <th class="px-4 py-3 text-right">مشتری</th>
            <th class="px-4 py-3 text-right">مبلغ</th>
            <th class="px-4 py-3 text-right">وضعیت</th>
            <th class="px-4 py-3 text-right">پرداخت</th>
            <th class="px-4 py-3 text-right">نوع</th>
            <th class="px-4 py-3 text-right">تاریخ</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($orders as $order)
          @php
          $statusColor = match($order->payment_status) { 'paid' => 'green', 'refunded' => 'orange', default => 'gray' };
          @endphp
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 font-mono text-xs">{{ $order->order_number }}</td>
            <td class="px-4 py-3">{{ $order->user?->full_name ?? $order->user?->mobile ?? '—' }}</td>
            <td class="px-4 py-3 font-bold">{{ number_format($order->total) }}</td>
            <td class="px-4 py-3">
              <span class="px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-700">{{ $order->status }}</span>
            </td>
            <td class="px-4 py-3">
              <span class="px-2 py-0.5 text-xs rounded-full bg-{{ $statusColor }}-100 text-{{ $statusColor }}-700">{{ $order->payment_status }}</span>
            </td>
            <td class="px-4 py-3 text-xs text-gray-400">{{ $order->created_at->format('Y/m/d') }}</td>
          </tr>
          @empty
          <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">سفارشی یافت نشد</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @if($orders->hasPages())
    <div class="p-4 border-t">{{ $orders->links() }}</div>
    @endif
  </div>

</div>
@endsection
