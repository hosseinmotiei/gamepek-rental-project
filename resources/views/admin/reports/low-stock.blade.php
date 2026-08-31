@extends('admin.layouts.app')
@section('title', 'گزارش کمبود موجودی')

@section('content')
<div class="space-y-6">

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">گزارش کمبود موجودی</h1>
      <p class="text-sm text-gray-500 mt-1">محصولات با موجودی کم یا ناموجود (آستانه: {{ $threshold }} عدد)</p>
    </div>
    <div class="flex gap-2">
      @can('export_reports')
      <a href="{{ route('admin.reports.low-stock.export') }}" class="inline-flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700">
        <i class="fas fa-download"></i> خروجی CSV
      </a>
      @endcan
      <a href="{{ route('admin.reports.index') }}" class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">
        <i class="fas fa-arrow-right"></i> بازگشت
      </a>
    </div>
  </div>

  {{-- Summary cards --}}
  <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-3">
    <div class="bg-white rounded-xl shadow p-5 text-center border-r-4 border-red-500">
      <div class="text-2xl font-bold text-red-600">{{ $outOfStock->count() }}</div>
      <div class="text-sm text-gray-500 mt-1">ناموجود (فیزیکی)</div>
    </div>
    <div class="bg-white rounded-xl shadow p-5 text-center border-r-4 border-orange-400">
      <div class="text-2xl font-bold text-orange-500">{{ $physicalLowStock->count() }}</div>
      <div class="text-sm text-gray-500 mt-1">کمبود موجودی</div>
    </div>
    </div>
    <div class="bg-white rounded-xl shadow p-5 text-center border-r-4 border-blue-400">
    </div>
    <div class="bg-white rounded-xl shadow p-5 text-center border-r-4 border-gray-400">
      <div class="text-2xl font-bold text-gray-600">{{ $comingSoon }}</div>
      <div class="text-sm text-gray-500 mt-1">به‌زودی</div>
    </div>
  </div>

  {{-- Physical low stock --}}
  @if($physicalLowStock->count())
  <div class="bg-white rounded-xl shadow border-r-4 border-orange-400">
    <div class="p-4 border-b bg-orange-50">
      <h2 class="font-semibold text-orange-800"><i class="fas fa-exclamation-triangle ml-2"></i>محصولات فیزیکی با کمبود موجودی (کمتر از {{ $threshold }})</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr>
            <th class="px-4 py-3 text-right">عنوان</th>
            <th class="px-4 py-3 text-right">SKU</th>
            <th class="px-4 py-3 text-right">دسته</th>
            <th class="px-4 py-3 text-right">موجودی</th>
            <th class="px-4 py-3 text-right">عملیات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @foreach($physicalLowStock as $p)
          <tr class="hover:bg-orange-50">
            <td class="px-4 py-3 font-medium">{{ $p->title_fa }}</td>
            <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $p->sku ?? '—' }}</td>
            <td class="px-4 py-3 text-xs text-gray-500">{{ $p->category?->name_fa ?? '—' }}</td>
            <td class="px-4 py-3">
              <span class="px-2 py-1 text-xs rounded font-bold {{ $p->stock_quantity <= 2 ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700' }}">
                {{ $p->stock_quantity }}
              </span>
            </td>
            <td class="px-4 py-3">
              <a href="{{ route('admin.products.edit', $p->id) }}" class="text-xs text-blue-600 hover:underline">ویرایش</a>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
  @endif

  {{-- Out of stock --}}
  @if($outOfStock->count())
  <div class="bg-white rounded-xl shadow border-r-4 border-red-500">
    <div class="p-4 border-b bg-red-50">
      <h2 class="font-semibold text-red-800"><i class="fas fa-times-circle ml-2"></i>محصولات ناموجود</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr><th class="px-4 py-3 text-right">عنوان</th><th class="px-4 py-3 text-right">دسته</th><th class="px-4 py-3 text-right">نوع</th><th class="px-4 py-3 text-right">عملیات</th></tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @foreach($outOfStock as $p)
          <tr class="hover:bg-red-50">
            <td class="px-4 py-3 font-medium">{{ $p->title_fa }}</td>
            <td class="px-4 py-3 text-xs text-gray-500">{{ $p->category?->name_fa ?? '—' }}</td>
            <td class="px-4 py-3"><a href="{{ route('admin.products.edit', $p->id) }}" class="text-xs text-blue-600 hover:underline">ویرایش</a></td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
  @endif

  {{-- Digital low codes --}}

  @if(!$physicalLowStock->count() && !$outOfStock->count())
  <div class="bg-green-50 border border-green-200 rounded-xl p-8 text-center">
    <i class="fas fa-check-circle text-green-500 text-4xl mb-3"></i>
    <p class="text-green-800 font-semibold">همه موجودی‌ها در سطح مناسب هستند.</p>
  </div>
  @endif

</div>
@endsection
