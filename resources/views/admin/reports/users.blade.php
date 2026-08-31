@extends('admin.layouts.app')
@section('title', 'گزارش کاربران')

@section('content')
<div class="space-y-6">

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">گزارش کاربران</h1>
      <p class="text-sm text-gray-500 mt-1">آمار ثبت‌نام، فعالیت و خرید کاربران</p>
    </div>
    <div class="flex gap-2">
      @can('export_reports')
      <a href="{{ route('admin.reports.users.export', request()->query()) }}" class="inline-flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700">
        <i class="fas fa-download"></i> خروجی CSV
      </a>
      @endcan
      <a href="{{ route('admin.reports.index') }}" class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">
        <i class="fas fa-arrow-right"></i> بازگشت
      </a>
    </div>
  </div>

  {{-- Date filter --}}
  <div class="bg-white rounded-xl shadow p-4">
    <form method="GET" action="{{ route('admin.reports.users') }}" class="flex flex-wrap gap-3 items-end">
      <div>
        <label class="block text-xs text-gray-600 mb-1">از تاریخ</label>
        <input type="date" name="date_from" value="{{ $dateFrom }}" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-1">تا تاریخ</label>
        <input type="date" name="date_to" value="{{ $dateTo }}" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
      </div>
      <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm hover:bg-blue-700">اعمال</button>
      <a href="{{ route('admin.reports.users') }}" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">ریست</a>
    </form>
  </div>

  {{-- Summary --}}
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
    @php $sCards = [
      ['total', 'کل کاربران', 'gray'], ['active', 'فعال', 'green'], ['blocked', 'مسدود', 'red'],
      ['with_orders', 'خریدار', 'blue'], ['new_month', 'این ماه', 'purple'],
    ] @endphp
    @foreach($sCards as [$key, $lbl, $clr])
    <div class="bg-white rounded-xl shadow p-4 text-center">
      <div class="text-xl font-bold text-{{ $clr }}-600">{{ number_format($summary[$key]) }}</div>
      <div class="text-xs text-gray-500 mt-1">{{ $lbl }}</div>
    </div>
    @endforeach
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- New users by date --}}
    <div class="bg-white rounded-xl shadow">
      <div class="p-4 border-b"><h2 class="font-semibold text-gray-900">ثبت‌نام روزانه</h2></div>
      <div class="overflow-x-auto max-h-72">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 sticky top-0">
            <tr><th class="px-4 py-2 text-right">تاریخ</th><th class="px-4 py-2 text-right">تعداد ثبت‌نام</th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            @forelse($newByDate as $row)
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2">{{ $row->date }}</td>
              <td class="px-4 py-2 font-bold text-blue-700">{{ $row->count }}</td>
            </tr>
            @empty
            <tr><td colspan="2" class="py-4 text-center text-gray-400">داده‌ای موجود نیست</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    {{-- Top customers --}}
    <div class="bg-white rounded-xl shadow">
      <div class="p-4 border-b"><h2 class="font-semibold text-gray-900">برترین خریداران</h2></div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500">
            <tr>
              <th class="px-4 py-2 text-right">#</th>
              <th class="px-4 py-2 text-right">کاربر</th>
              <th class="px-4 py-2 text-right">سفارشات</th>
              <th class="px-4 py-2 text-right">جمع خرید</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            @forelse($topCustomers as $i => $c)
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2 font-bold text-gray-400">{{ $i+1 }}</td>
              <td class="px-4 py-2">
                <div class="font-medium text-gray-800">{{ $c->full_name ?: '—' }}</div>
                <div class="text-xs text-gray-400">{{ $c->mobile }}</div>
              </td>
              <td class="px-4 py-2 text-blue-700 font-bold">{{ $c->order_count }}</td>
              <td class="px-4 py-2 text-green-700 font-bold">{{ number_format($c->total_spent) }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="py-4 text-center text-gray-400">داده‌ای موجود نیست</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {{-- Recent users --}}
  <div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b"><h2 class="font-semibold text-gray-900">آخرین ثبت‌نام‌ها</h2></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr>
            <th class="px-4 py-3 text-right">نام</th>
            <th class="px-4 py-3 text-right">موبایل</th>
            <th class="px-4 py-3 text-right">وضعیت</th>
            <th class="px-4 py-3 text-right">تاریخ ثبت‌نام</th>
            <th class="px-4 py-3 text-right">عملیات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($recentUsers as $u)
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3">{{ $u->full_name ?: '—' }}</td>
            <td class="px-4 py-3 font-mono text-sm">{{ $u->mobile }}</td>
            <td class="px-4 py-3">
              <span class="px-2 py-0.5 text-xs rounded-full {{ $u->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                {{ $u->status === 'active' ? 'فعال' : 'مسدود' }}
              </span>
            </td>
            <td class="px-4 py-3 text-gray-400 text-xs">{{ $u->created_at->format('Y/m/d H:i') }}</td>
            <td class="px-4 py-3">
              <a href="{{ route('admin.users.show', $u->id) }}" class="text-xs text-blue-600 hover:underline">مشاهده</a>
            </td>
          </tr>
          @empty
          <tr><td colspan="5" class="py-6 text-center text-gray-400">کاربری یافت نشد</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
