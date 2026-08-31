@extends('admin.layouts.app')
@section('title', 'گزارش محصولات')

@section('content')
<div class="space-y-6">

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">گزارش محصولات</h1>
      <p class="text-sm text-gray-500 mt-1">آمار و تحلیل محصولات فروشگاه</p>
    </div>
    <div class="flex gap-2">
      @can('export_reports')
      <a href="{{ route('admin.reports.products.export', request()->query()) }}" class="inline-flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700">
        <i class="fas fa-download"></i> خروجی CSV
      </a>
      @endcan
      <a href="{{ route('admin.reports.index') }}" class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">
        <i class="fas fa-arrow-right"></i> بازگشت
      </a>
    </div>
  </div>

  {{-- Summary cards --}}
  <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
    @php $cards = [
      ['total', 'کل', 'gray'], ['active', 'فعال', 'green'], ['inactive', 'غیرفعال', 'red'],
      ['out_of_stock', 'ناموجود', 'red'],
    ] @endphp
    @foreach($cards as [$key, $lbl, $clr])
    <div class="bg-white rounded-xl shadow p-4 text-center">
      <div class="text-lg font-bold text-{{ $clr }}-600">{{ $summary[$key] }}</div>
      <div class="text-xs text-gray-500 mt-1">{{ $lbl }}</div>
    </div>
    @endforeach
  </div>

  {{-- Top selling --}}
  <div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b"><h2 class="font-semibold text-gray-900">پرفروش‌ترین محصولات</h2></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr>
            <th class="px-4 py-3 text-right">#</th>
            <th class="px-4 py-3 text-right">محصول</th>
            <th class="px-4 py-3 text-right">نوع</th>
            <th class="px-4 py-3 text-right">تعداد فروش</th>
            <th class="px-4 py-3 text-right">درآمد (تومان)</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($topSelling as $i => $p)
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 text-gray-400 font-bold">{{ $i+1 }}</td>
            <td class="px-4 py-3 font-medium">{{ $p->title_fa }}</td>
            <td class="px-4 py-3 font-bold text-blue-700">{{ number_format($p->total_sold) }}</td>
            <td class="px-4 py-3 font-bold text-green-700">{{ number_format($p->total_revenue) }}</td>
          </tr>
          @empty
          <tr><td colspan="5" class="py-6 text-center text-gray-400">داده‌ای موجود نیست</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- Filters + product list --}}
  <div class="bg-white rounded-xl shadow p-4">
    <form method="GET" action="{{ route('admin.reports.products') }}" class="flex flex-wrap gap-3 items-end mb-4">
      <div>
        <label class="block text-xs text-gray-600 mb-1">دسته‌بندی</label>
        <select name="category_id" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
          <option value="">همه دسته‌ها</option>
          @foreach($categories as $cat)
          <option value="{{ $cat->id }}" {{ $categoryId == $cat->id ? 'selected' : '' }}>{{ $cat->name_fa }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-1">وضعیت موجودی</label>
        <select name="stock_status" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
          <option value="">همه</option>
          <option value="in_stock" {{ $stockStatus === 'in_stock' ? 'selected' : '' }}>موجود</option>
          <option value="out_of_stock" {{ $stockStatus === 'out_of_stock' ? 'selected' : '' }}>ناموجود</option>
          <option value="low_stock" {{ $stockStatus === 'low_stock' ? 'selected' : '' }}>کم موجودی</option>
        </select>
      </div>
      <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm hover:bg-blue-700">اعمال</button>
      <a href="{{ route('admin.reports.products') }}" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">ریست</a>
    </form>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr>
            <th class="px-4 py-3 text-right">عنوان</th>
            <th class="px-4 py-3 text-right">SKU</th>
            <th class="px-4 py-3 text-right">دسته</th>
            <th class="px-4 py-3 text-right">نوع</th>
            <th class="px-4 py-3 text-right">موجودی</th>
            <th class="px-4 py-3 text-right">قیمت</th>
            <th class="px-4 py-3 text-right">وضعیت</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($products as $p)
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 font-medium">{{ $p->title_fa }}</td>
            <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $p->sku ?? '—' }}</td>
            <td class="px-4 py-3 text-xs text-gray-500">{{ $p->category?->name_fa ?? '—' }}</td>
            <td class="px-4 py-3">
            </td>
            <td class="px-4 py-3">{{ number_format($p->price) }}</td>
            <td class="px-4 py-3">
              <span class="px-2 py-0.5 text-xs rounded-full {{ $p->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                {{ $p->is_active ? 'فعال' : 'غیرفعال' }}
              </span>
            </td>
          </tr>
          @empty
          <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">محصولی یافت نشد</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @if($products->hasPages())
    <div class="p-4 border-t">{{ $products->links() }}</div>
    @endif
  </div>

</div>
@endsection
