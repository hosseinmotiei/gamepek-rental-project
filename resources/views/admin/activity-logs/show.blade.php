@extends('admin.layouts.app')
@section('title', 'جزئیات لاگ فعالیت')

@section('content')
<div class="space-y-6 max-w-3xl">

  <div class="flex items-center justify-between flex-wrap gap-3">
    <h1 class="text-2xl font-bold text-gray-900">جزئیات فعالیت</h1>
    <a href="{{ route('admin.activity-logs.index') }}" class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">
      <i class="fas fa-arrow-right"></i> بازگشت به لیست
    </a>
  </div>

  <div class="bg-white rounded-xl shadow divide-y divide-gray-100">

    {{-- Action + time --}}
    <div class="p-5 flex items-start justify-between gap-4">
      <div>
        <span class="px-3 py-1 text-sm rounded font-mono bg-blue-100 text-blue-800">{{ $activityLog->action }}</span>
        <p class="text-xs text-gray-400 mt-2">{{ $activityLog->created_at->format('Y-m-d H:i:s') }} ({{ $activityLog->created_at->diffForHumans() }})</p>
      </div>
      <span class="text-xs text-gray-400 font-mono">#{{ $activityLog->id }}</span>
    </div>

    {{-- Admin --}}
    <div class="p-5 grid grid-cols-2 gap-4 text-sm">
      <div>
        <p class="text-xs text-gray-400 mb-1">ادمین</p>
        <p class="font-medium text-gray-800">{{ $activityLog->admin?->full_name ?: $activityLog->admin?->mobile ?? 'حذف شده' }}</p>
      </div>
      @if($activityLog->admin)
      <div>
        <p class="text-xs text-gray-400 mb-1">موبایل</p>
        <p class="font-mono text-gray-800">{{ $activityLog->admin->mobile }}</p>
      </div>
      @endif
    </div>

    {{-- Subject --}}
    @if($activityLog->subject_type)
    <div class="p-5 text-sm">
      <p class="text-xs text-gray-400 mb-1">موضوع اقدام</p>
      <p class="font-medium">{{ $activityLog->subject_type }} @if($activityLog->subject_id)<span class="text-gray-400">#{{ $activityLog->subject_id }}</span>@endif</p>
    </div>
    @endif

    {{-- Description --}}
    @if($activityLog->description)
    <div class="p-5 text-sm">
      <p class="text-xs text-gray-400 mb-1">توضیح</p>
      <p class="text-gray-800">{{ $activityLog->description }}</p>
    </div>
    @endif

    {{-- Properties --}}
    @if($activityLog->properties)
    <div class="p-5">
      <p class="text-xs text-gray-400 mb-2">اطلاعات تکمیلی</p>
      <pre class="bg-gray-50 rounded-lg p-3 text-xs text-gray-800 overflow-x-auto leading-relaxed">{{ json_encode($activityLog->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    @endif

    {{-- Network --}}
    <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
      <div>
        <p class="text-xs text-gray-400 mb-1">آدرس IP</p>
        <p class="font-mono text-gray-800">{{ $activityLog->ip_address ?? '—' }}</p>
      </div>
      @if($activityLog->user_agent)
      <div>
        <p class="text-xs text-gray-400 mb-1">مرورگر / User-Agent</p>
        <p class="text-xs text-gray-600 break-all">{{ $activityLog->user_agent }}</p>
      </div>
      @endif
    </div>

  </div>

</div>
@endsection
