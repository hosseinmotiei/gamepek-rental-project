{{--
    Read-only view over the append-only audit trail. There is deliberately no
    create/edit/delete action here — an audit row is never written by a screen.
--}}
@extends('admin.layouts.app')

@section('title', 'ممیزی')
@section('page_badge', 'ممیزی')
@section('breadcrumb') <span class="text-gray-700 text-sm">لاگ ممیزی</span> @endsection

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">لاگ ممیزی</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ persian_number($events->total()) }} رویداد</p>
    </div>
</div>

<x-admin.filter-bar :action="route('admin.audit-events.index')"
                    :params="['action', 'result', 'resource_type', 'correlation_id']" submit-label="فیلتر">
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1.5">اقدام</label>
        <input type="text" name="action" value="{{ request('action') }}" placeholder="order.paid"
               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2 text-sm outline-none focus:border-brandBlue" dir="ltr">
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1.5">نتیجه</label>
        <select name="result" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue">
            <option value="">همه</option>
            <option value="success" {{ request('result') === 'success' ? 'selected' : '' }}>موفق</option>
            <option value="failure" {{ request('result') === 'failure' ? 'selected' : '' }}>ناموفق</option>
            <option value="denied"  {{ request('result') === 'denied'  ? 'selected' : '' }}>رد شده</option>
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1.5">نوع منبع</label>
        <select name="resource_type" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue">
            <option value="">همه</option>
            @foreach($resourceTypes as $type)
                <option value="{{ $type }}" {{ request('resource_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1.5">شناسه همبستگی</label>
        <input type="text" name="correlation_id" value="{{ request('correlation_id') }}"
               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2 text-sm outline-none focus:border-brandBlue" dir="ltr">
    </div>
</x-admin.filter-bar>

<x-admin.panel>
    @if($events->isEmpty())
        <x-admin.empty-state icon="fa-shield-halved" message="رویداد ممیزی‌ای یافت نشد." />
    @else
        <x-admin.table :paginator="$events">
            <x-slot:head>
                <tr class="border-b border-gray-100 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">زمان</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">اقدام</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">عامل</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">منبع</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">نتیجه</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">شناسه همبستگی</th>
                </tr>
            </x-slot:head>

            @foreach($events as $event)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $event->occurred_at?->format('Y/m/d H:i:s') }}</td>
                <td class="px-4 py-3 text-xs font-mono text-gray-800" dir="ltr">{{ $event->action }}</td>
                <td class="px-4 py-3">
                    <p class="text-xs text-gray-700">{{ $event->actor_label ?: $event->actor_type }}</p>
                    <p class="text-[10px] text-gray-400" dir="ltr">{{ $event->ip_address ?: '—' }}</p>
                </td>
                <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500" dir="ltr">
                    {{ $event->resource_type ? $event->resource_type.'#'.$event->resource_id : '—' }}
                </td>
                <td class="px-4 py-3">
                    <x-admin.status-badge :color="match($event->result) { 'success' => 'green', 'failure' => 'red', 'denied' => 'yellow', default => 'gray' }"
                                          :label="$event->result" />
                </td>
                <td class="px-4 py-3 hidden lg:table-cell">
                    <a href="{{ route('admin.audit-events.index', ['correlation_id' => $event->correlation_id]) }}"
                       class="text-[10px] font-mono text-brandBlue" dir="ltr">{{ $event->correlation_id }}</a>
                </td>
            </tr>
            @endforeach
        </x-admin.table>
    @endif
</x-admin.panel>

@endsection
