@extends('admin.layouts.app')
@section('title', 'لاگ فعالیت‌ها')

@section('content')
<div class="space-y-6">

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">لاگ فعالیت‌های ادمین</h1>
      <p class="text-sm text-gray-500 mt-1">تاریخچه کلیه اقدامات ادمین‌ها</p>
    </div>
  </div>

  {{-- Filters --}}
  <div class="bg-white rounded-xl shadow p-4">
    <form method="GET" action="{{ route('admin.activity-logs.index') }}" class="flex flex-wrap gap-3 items-end">
      <div>
        <label class="block text-xs text-gray-600 mb-1">جستجو</label>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="اقدام یا توضیح..." class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none w-48">
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-1">ادمین</label>
        <select name="admin_id" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
          <option value="">همه ادمین‌ها</option>
          @foreach($admins as $admin)
          <option value="{{ $admin->id }}" {{ request('admin_id') == $admin->id ? 'selected' : '' }}>
            {{ $admin->full_name ?: $admin->mobile }}
          </option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-1">نوع اقدام</label>
        <input type="text" name="action" value="{{ request('action') }}" placeholder="مثلاً: post.create" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none w-40">
      </div>
      @if($subjectTypes->count())
      <div>
        <label class="block text-xs text-gray-600 mb-1">نوع موضوع</label>
        <select name="subject_type" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
          <option value="">همه</option>
          @foreach($subjectTypes as $st)
          <option value="{{ $st }}" {{ request('subject_type') === $st ? 'selected' : '' }}>{{ $st }}</option>
          @endforeach
        </select>
      </div>
      @endif
      <div>
        <label class="block text-xs text-gray-600 mb-1">از تاریخ</label>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-1">تا تاریخ</label>
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
      </div>
      <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm hover:bg-blue-700">اعمال</button>
      <a href="{{ route('admin.activity-logs.index') }}" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">ریست</a>
    </form>
  </div>

  {{-- Logs table --}}
  <div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex items-center justify-between">
      <h2 class="font-semibold text-gray-900">{{ number_format($logs->total()) }} فعالیت</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr>
            <th class="px-4 py-3 text-right">ادمین</th>
            <th class="px-4 py-3 text-right">اقدام</th>
            <th class="px-4 py-3 text-right">موضوع</th>
            <th class="px-4 py-3 text-right">توضیح</th>
            <th class="px-4 py-3 text-right">IP</th>
            <th class="px-4 py-3 text-right">زمان</th>
            <th class="px-4 py-3 text-right"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($logs as $log)
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3">
              <div class="font-medium text-gray-800">{{ $log->admin?->full_name ?: $log->admin?->mobile ?? 'حذف شده' }}</div>
            </td>
            <td class="px-4 py-3">
              @php
              $actionColor = match(true) {
                str_starts_with($log->action, 'post') => 'purple',
                str_starts_with($log->action, 'review') => 'yellow',
                str_starts_with($log->action, 'question') => 'blue',
                str_starts_with($log->action, 'report') => 'green',
                str_starts_with($log->action, 'order') => 'indigo',
                str_starts_with($log->action, 'product') => 'teal',
                default => 'gray',
              };
              @endphp
              <span class="px-2 py-0.5 text-xs rounded font-mono bg-{{ $actionColor }}-100 text-{{ $actionColor }}-800">{{ $log->action }}</span>
            </td>
            <td class="px-4 py-3 text-xs text-gray-500">
              @if($log->subject_type)
              <span>{{ $log->subject_type }}</span>
              @if($log->subject_id)<span class="text-gray-400"> #{{ $log->subject_id }}</span>@endif
              @else—@endif
            </td>
            <td class="px-4 py-3 text-gray-700 max-w-xs truncate">{{ $log->description ?? '—' }}</td>
            <td class="px-4 py-3 font-mono text-xs text-gray-400">{{ $log->ip_address ?? '—' }}</td>
            <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
              <span title="{{ $log->created_at->format('Y-m-d H:i:s') }}">{{ $log->created_at->diffForHumans() }}</span>
            </td>
            <td class="px-4 py-3">
              <a href="{{ route('admin.activity-logs.show', $log->id) }}" class="text-xs text-blue-600 hover:underline">جزئیات</a>
            </td>
          </tr>
          @empty
          <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">هیچ فعالیتی ثبت نشده</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @if($logs->hasPages())
    <div class="p-4 border-t">{{ $logs->links() }}</div>
    @endif
  </div>

</div>
@endsection
