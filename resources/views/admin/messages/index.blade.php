@extends('admin.layouts.app')

@section('title', 'پیام‌ها')
@section('page_badge', 'پیام‌ها')
@section('breadcrumb') <span class="text-gray-700 text-sm">پیام‌ها</span> @endsection

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">مدیریت پیام‌های پشتیبانی</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ $conversations->total() }} گفتگو</p>
    </div>
</div>

{{-- Status summary chips --}}
<div class="flex flex-wrap gap-2 mb-5">
    <a href="{{ route('admin.messages.index', request()->except('status')) }}"
       class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full font-medium border transition-colors {{ !request('status') ? 'bg-brandBlue text-white border-brandBlue' : 'bg-white text-gray-600 border-gray-200 hover:border-brandBlue hover:text-brandBlue' }}">
        همه
        <span class="font-bold">{{ $conversations->total() }}</span>
    </a>
    @foreach(['open' => 'باز', 'closed' => 'بسته شده'] as $key => $label)
    @if(($statusCounts[$key] ?? 0) > 0)
    <a href="{{ route('admin.messages.index', array_merge(request()->except('status', 'page'), ['status' => $key])) }}"
       class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full font-medium border transition-colors {{ request('status') === $key ? 'bg-brandBlue text-white border-brandBlue' : 'bg-white text-gray-600 border-gray-200 hover:border-brandBlue hover:text-brandBlue' }}">
        {{ $label }}
        <span class="font-bold">{{ $statusCounts[$key] ?? 0 }}</span>
    </a>
    @endif
    @endforeach
</div>

{{-- Filters --}}
<form method="GET" action="{{ route('admin.messages.index') }}" class="bg-white rounded-2xl border border-gray-200 p-4 mb-5 shadow-sm">
    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[220px]">
            <label class="block text-xs font-medium text-gray-600 mb-1.5">جستجو</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="موضوع، نام یا موبایل کاربر..."
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="bg-brandBlue text-white text-sm font-bold px-4 py-2 rounded-xl hover:bg-blue-700 transition-colors">
                <i class="fa-solid fa-search ml-1"></i> جستجو
            </button>
            @if(request()->hasAny(['search', 'status']))
            <a href="{{ route('admin.messages.index') }}" class="text-sm text-gray-500 border border-gray-200 px-3 py-2 rounded-xl hover:bg-gray-50 transition-colors">
                <i class="fa-solid fa-times"></i>
            </a>
            @endif
        </div>
    </div>
</form>

{{-- Table --}}
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
    @if($conversations->isEmpty())
    <div class="py-16 text-center text-gray-400">
        <i class="fa-regular fa-comment-dots text-4xl mb-3"></i>
        <p class="text-sm">گفتگویی یافت نشد.</p>
    </div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">موضوع</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">کاربر</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">وضعیت</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">آخرین پیام</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs text-left">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($conversations as $conversation)
                <tr class="hover:bg-gray-50 transition-colors {{ $conversation->isUnreadByAdmin() ? 'bg-blue-50/40' : '' }}">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.messages.show', $conversation) }}" class="font-bold text-xs text-brandBlue hover:underline flex items-center gap-2">
                            @if($conversation->isUnreadByAdmin())
                            <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                            @endif
                            {{ $conversation->subject }}
                        </a>
                    </td>
                    <td class="px-4 py-3">
                        @if($conversation->user)
                        <p class="text-sm font-medium text-gray-800">{{ $conversation->user->full_name }}</p>
                        <p class="text-[11px] text-gray-400" dir="ltr">{{ $conversation->user->mobile }}</p>
                        @else
                        <span class="text-gray-400 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center text-[10px] font-medium px-2 py-1 rounded-full {{ $conversation->status === 'open' ? 'text-blue-700 bg-blue-50' : 'text-gray-500 bg-gray-100' }}">
                            {{ $conversation->status_label }}
                        </span>
                    </td>
                    <td class="px-4 py-3 hidden lg:table-cell">
                        <span class="text-xs text-gray-500">{{ $conversation->last_message_at?->diffForHumans() }}</span>
                    </td>
                    <td class="px-4 py-3 text-left">
                        <a href="{{ route('admin.messages.show', $conversation) }}"
                           class="inline-flex items-center gap-1 text-xs text-brandBlue hover:underline font-medium">
                            <i class="fa-solid fa-eye text-[10px]"></i> مشاهده
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($conversations->hasPages())
    <div class="px-4 py-3 border-t border-gray-100">
        {{ $conversations->links() }}
    </div>
    @endif
    @endif
</div>

@endsection
