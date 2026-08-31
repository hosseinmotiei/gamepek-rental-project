@extends('admin.layouts.app')
@section('title', 'داشبورد گزارش‌ها')

@section('content')
<div class="space-y-6">

  {{-- Header --}}
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">داشبورد گزارش‌ها</h1>
      <p class="text-sm text-gray-500 mt-1">نمای کلی وضعیت فروشگاه</p>
    </div>
    <span class="text-sm text-gray-400">{{ now()->format('Y/m/d - H:i') }}</span>
  </div>

  {{-- Revenue cards --}}
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="bg-white rounded-xl shadow p-5 flex items-start gap-4">
      <div class="p-3 bg-green-100 rounded-lg"><i class="fas fa-money-bill-wave text-green-600 text-xl"></i></div>
      <div>
        <p class="text-xs text-gray-500">درآمد کل</p>
        <p class="text-xl font-bold text-gray-900">{{ number_format($stats['total_revenue']) }}</p>
        <p class="text-xs text-gray-400">تومان</p>
      </div>
    </div>
    <div class="bg-white rounded-xl shadow p-5 flex items-start gap-4">
      <div class="p-3 bg-blue-100 rounded-lg"><i class="fas fa-calendar-day text-blue-600 text-xl"></i></div>
      <div>
        <p class="text-xs text-gray-500">درآمد امروز</p>
        <p class="text-xl font-bold text-gray-900">{{ number_format($stats['today_revenue']) }}</p>
        <p class="text-xs text-gray-400">تومان</p>
      </div>
    </div>
    <div class="bg-white rounded-xl shadow p-5 flex items-start gap-4">
      <div class="p-3 bg-purple-100 rounded-lg"><i class="fas fa-calendar-alt text-purple-600 text-xl"></i></div>
      <div>
        <p class="text-xs text-gray-500">درآمد این ماه</p>
        <p class="text-xl font-bold text-gray-900">{{ number_format($stats['month_revenue']) }}</p>
        <p class="text-xs text-gray-400">تومان</p>
      </div>
    </div>
    <div class="bg-white rounded-xl shadow p-5 flex items-start gap-4">
      <div class="p-3 bg-yellow-100 rounded-lg"><i class="fas fa-shopping-cart text-yellow-600 text-xl"></i></div>
      <div>
        <p class="text-xs text-gray-500">سفارش‌های پرداخت شده</p>
        <p class="text-xl font-bold text-gray-900">{{ number_format($stats['paid_orders']) }}</p>
        <p class="text-xs text-gray-400">از {{ number_format($stats['total_orders']) }} کل</p>
      </div>
    </div>
  </div>

  {{-- Quick stats --}}
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
    @php $quickStats = [
      ['label' => 'کاربران', 'value' => $stats['total_users'], 'sub' => '+' . $stats['new_users_month'] . ' این ماه', 'color' => 'indigo', 'icon' => 'fa-users'],
      ['label' => 'محصولات فعال', 'value' => $stats['active_products'], 'sub' => $stats['total_products'] . ' کل', 'color' => 'blue', 'icon' => 'fa-box'],
      ['label' => 'کمبود موجودی', 'value' => $stats['low_stock_products'], 'sub' => $stats['out_of_stock'] . ' ناموجود', 'color' => 'red', 'icon' => 'fa-exclamation-triangle'],
    ] @endphp
    @foreach($quickStats as $qs)
    <div class="bg-white rounded-xl shadow p-4 text-center">
      <i class="fas {{ $qs['icon'] }} text-{{ $qs['color'] }}-500 text-lg mb-1"></i>
      <div class="text-lg font-bold text-gray-900">{{ number_format($qs['value']) }}</div>
      <div class="text-xs text-gray-500 truncate">{{ $qs['label'] }}</div>
      <div class="text-xs text-gray-400">{{ $qs['sub'] }}</div>
    </div>
    @endforeach
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Recent paid orders --}}
    <div class="lg:col-span-2 bg-white rounded-xl shadow">
      <div class="p-4 border-b flex items-center justify-between">
        <h2 class="font-semibold text-gray-900"><i class="fas fa-shopping-bag text-blue-500 ml-2"></i>آخرین سفارشات پرداخت شده</h2>
        @can('view_order_reports')<a href="{{ route('admin.reports.orders') }}" class="text-xs text-blue-600 hover:underline">مشاهده همه</a>@endcan
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500">
            <tr>
              <th class="px-4 py-2 text-right">شماره سفارش</th>
              <th class="px-4 py-2 text-right">مشتری</th>
              <th class="px-4 py-2 text-right">مبلغ</th>
              <th class="px-4 py-2 text-right">تاریخ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            @forelse($recentOrders as $order)
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2 font-mono text-xs">{{ $order->order_number }}</td>
              <td class="px-4 py-2">{{ $order->user?->full_name ?? $order->user?->mobile ?? '—' }}</td>
              <td class="px-4 py-2 font-bold text-green-700">{{ number_format($order->total) }}</td>
              <td class="px-4 py-2 text-gray-400 text-xs">{{ $order->paid_at?->format('m/d H:i') }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">سفارشی ثبت نشده</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    {{-- Side panel --}}
    <div class="space-y-4">
      {{-- Top products --}}
      <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
          <h2 class="font-semibold text-gray-900"><i class="fas fa-trophy text-yellow-500 ml-2"></i>پرفروش‌ترین محصولات</h2>
        </div>
        <ul class="divide-y divide-gray-100">
          @forelse($topProducts as $i => $p)
          <li class="px-4 py-2 flex items-center justify-between">
            <div class="flex items-center gap-2 min-w-0">
              <span class="text-xs font-bold text-gray-400 w-4">{{ $i+1 }}</span>
              <span class="text-sm text-gray-800 truncate">{{ $p->title_fa }}</span>
            </div>
            <span class="text-xs font-bold text-blue-600 shrink-0">{{ $p->total_sold }} عدد</span>
          </li>
          @empty
          <li class="px-4 py-3 text-center text-sm text-gray-400">داده‌ای موجود نیست</li>
          @endforelse
        </ul>
      </div>

      {{-- Low stock alert --}}
      @if($lowStockProducts->count())
      <div class="bg-white rounded-xl shadow border-r-4 border-red-500">
        <div class="p-4 border-b flex items-center justify-between">
          <h2 class="font-semibold text-red-700"><i class="fas fa-exclamation-triangle ml-2"></i>کمبود موجودی</h2>
          @can('view_low_stock_reports')<a href="{{ route('admin.reports.low-stock') }}" class="text-xs text-red-600 hover:underline">همه</a>@endcan
        </div>
        <ul class="divide-y divide-gray-100">
          @foreach($lowStockProducts as $p)
          <li class="px-4 py-2 flex items-center justify-between">
            <span class="text-sm text-gray-700 truncate">{{ $p->title_fa }}</span>
            <span class="text-xs font-bold {{ $p->stock_quantity <= 2 ? 'text-red-600' : 'text-orange-500' }}">{{ $p->stock_quantity }}</span>
          </li>
          @endforeach
        </ul>
      </div>
      @endif
    </div>
  </div>

  {{-- Report links grid --}}
  <div class="bg-white rounded-xl shadow p-6">
    <h2 class="font-semibold text-gray-900 mb-4"><i class="fas fa-chart-bar text-blue-500 ml-2"></i>گزارش‌های تخصصی</h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3">
      @php
      $reportLinks = [
        ['route' => 'admin.reports.sales',       'perm' => 'view_sales_reports',   'icon' => 'fa-chart-line',     'label' => 'فروش',          'color' => 'green'],
        ['route' => 'admin.reports.orders',      'perm' => 'view_order_reports',   'icon' => 'fa-shopping-cart',  'label' => 'سفارشات',       'color' => 'blue'],
        ['route' => 'admin.reports.products',    'perm' => 'view_product_reports', 'icon' => 'fa-box-open',       'label' => 'محصولات',       'color' => 'purple'],
        ['route' => 'admin.reports.categories',  'perm' => 'view_category_reports','icon' => 'fa-layer-group',    'label' => 'دسته‌بندی‌ها',  'color' => 'indigo'],
        ['route' => 'admin.reports.users',       'perm' => 'view_user_reports',    'icon' => 'fa-users',          'label' => 'کاربران',       'color' => 'teal'],
        ['route' => 'admin.reports.low-stock',   'perm' => 'view_low_stock_reports','icon'=> 'fa-exclamation-circle','label'=>'کمبود موجودی','color' => 'red'],
        ['route' => 'admin.activity-logs.index', 'perm' => 'view_activity_logs',   'icon' => 'fa-history',        'label' => 'لاگ فعالیت',   'color' => 'gray'],
      ];
      @endphp
      @foreach($reportLinks as $rl)
        @can($rl['perm'])
        <a href="{{ route($rl['route']) }}" class="flex flex-col items-center gap-2 p-4 rounded-lg border border-gray-200 hover:border-blue-400 hover:bg-blue-50 transition text-center group">
          <i class="fas {{ $rl['icon'] }} text-{{ $rl['color'] }}-500 text-2xl group-hover:scale-110 transition-transform"></i>
          <span class="text-xs font-medium text-gray-700">{{ $rl['label'] }}</span>
        </a>
        @endcan
      @endforeach
    </div>
  </div>

  {{-- Recent activity logs --}}
  @can('view_activity_logs')
  <div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex items-center justify-between">
      <h2 class="font-semibold text-gray-900"><i class="fas fa-history text-gray-500 ml-2"></i>آخرین فعالیت‌ها</h2>
      <a href="{{ route('admin.activity-logs.index') }}" class="text-xs text-blue-600 hover:underline">مشاهده همه</a>
    </div>
    <ul class="divide-y divide-gray-100">
      @forelse($recentLogs as $log)
      <li class="px-4 py-3 flex items-center gap-3">
        <span class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600 shrink-0">
          {{ mb_substr($log->admin?->full_name ?? '؟', 0, 1) }}
        </span>
        <div class="min-w-0">
          <p class="text-sm text-gray-800">
            <span class="font-medium">{{ $log->admin?->full_name ?? 'حذف شده' }}</span>
            — <span class="text-gray-500">{{ $log->description ?? $log->action }}</span>
          </p>
          <p class="text-xs text-gray-400">{{ $log->created_at->diffForHumans() }}</p>
        </div>
      </li>
      @empty
      <li class="px-4 py-4 text-center text-sm text-gray-400">هیچ فعالیتی ثبت نشده</li>
      @endforelse
    </ul>
  </div>
  @endcan

</div>
@endsection
