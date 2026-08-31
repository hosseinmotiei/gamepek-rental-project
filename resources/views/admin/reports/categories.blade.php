@extends('admin.layouts.app')
@section('title', 'گزارش دسته‌بندی‌ها')

@section('content')
<div class="space-y-6">

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">گزارش دسته‌بندی‌ها</h1>
      <p class="text-sm text-gray-500 mt-1">آمار فروش و محصولات بر اساس دسته‌بندی</p>
    </div>
    <a href="{{ route('admin.reports.index') }}" class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">
      <i class="fas fa-arrow-right"></i> بازگشت
    </a>
  </div>

  {{-- Summary --}}
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    <div class="bg-white rounded-xl shadow p-5 text-center"><div class="text-2xl font-bold text-gray-900">{{ $summary['total'] }}</div><div class="text-sm text-gray-500 mt-1">کل دسته‌بندی</div></div>
    <div class="bg-white rounded-xl shadow p-5 text-center"><div class="text-2xl font-bold text-green-600">{{ $summary['active'] }}</div><div class="text-sm text-gray-500 mt-1">فعال</div></div>
    <div class="bg-white rounded-xl shadow p-5 text-center"><div class="text-2xl font-bold text-red-500">{{ $summary['inactive'] }}</div><div class="text-sm text-gray-500 mt-1">غیرفعال</div></div>
    <div class="bg-white rounded-xl shadow p-5 text-center"><div class="text-2xl font-bold text-blue-600">{{ $summary['root'] }}</div><div class="text-sm text-gray-500 mt-1">دسته اصلی</div></div>
  </div>

  {{-- Sales by category --}}
  <div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b"><h2 class="font-semibold text-gray-900">فروش بر اساس دسته‌بندی</h2></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr>
            <th class="px-4 py-3 text-right">دسته‌بندی</th>
            <th class="px-4 py-3 text-right">درآمد (تومان)</th>
            <th class="px-4 py-3 text-right">اقلام فروخته شده</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($salesByCategory->sortByDesc('revenue') as $row)
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 font-medium">{{ $row->name_fa }}</td>
            <td class="px-4 py-3 font-bold text-green-700">{{ number_format($row->revenue) }}</td>
            <td class="px-4 py-3 text-blue-700">{{ number_format($row->items_sold) }}</td>
          </tr>
          @empty
          <tr><td colspan="3" class="py-6 text-center text-gray-400">داده‌ای موجود نیست</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- Categories table --}}
  <div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b"><h2 class="font-semibold text-gray-900">آمار محصولات بر اساس دسته</h2></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr>
            <th class="px-4 py-3 text-right">دسته‌بندی</th>
            <th class="px-4 py-3 text-right">کل محصولات</th>
            <th class="px-4 py-3 text-right">محصولات فعال</th>
            <th class="px-4 py-3 text-right">وضعیت</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($categories as $cat)
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3">
              <span class="font-medium">{{ $cat->name_fa }}</span>
              @if($cat->parent_id)<span class="text-xs text-gray-400 mr-1">(زیردسته)</span>@endif
            </td>
            <td class="px-4 py-3 font-bold">{{ $cat->products_count }}</td>
            <td class="px-4 py-3 text-green-700">{{ $cat->active_products_count }}</td>
            <td class="px-4 py-3">
              <span class="px-2 py-0.5 text-xs rounded-full {{ $cat->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                {{ $cat->is_active ? 'فعال' : 'غیرفعال' }}
              </span>
            </td>
          </tr>
          @empty
          <tr><td colspan="4" class="py-6 text-center text-gray-400">دسته‌بندی موجود نیست</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
