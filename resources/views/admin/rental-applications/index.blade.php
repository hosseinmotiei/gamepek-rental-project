@extends('admin.layouts.app')

@section('title', 'درخواست‌های اجاره')
@section('page_badge', 'اجاره')
@section('breadcrumb') <span class="text-gray-700 text-sm">درخواست‌های اجاره</span> @endsection

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">درخواست‌های اجاره</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ persian_number($applications->total()) }} درخواست</p>
    </div>
</div>

<x-admin.filter-bar :action="route('admin.rental-applications.index')" :params="['search', 'state']" submit-label="جستجو">
    <div class="sm:col-span-2">
        <label class="block text-xs font-medium text-gray-600 mb-1.5">جستجو</label>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="شماره درخواست، نام یا موبایل..."
               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all">
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1.5">وضعیت</label>
        <select name="state" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue">
            <option value="">همه</option>
            @foreach($states as $state)
                <option value="{{ $state->value }}" {{ request('state') === $state->value ? 'selected' : '' }}>{{ $state->label() }}</option>
            @endforeach
        </select>
    </div>
</x-admin.filter-bar>

<x-admin.panel>
    @if($applications->isEmpty())
        <x-admin.empty-state icon="fa-file-signature" message="درخواست اجاره‌ای یافت نشد." />
    @else
        <x-admin.table :paginator="$applications">
            <x-slot:head>
                <tr class="border-b border-gray-100 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">شماره</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">کاربر</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">دستگاه</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">وضعیت</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">بازه</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs text-left">عملیات</th>
                </tr>
            </x-slot:head>

            @foreach($applications as $application)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3"><span class="text-xs font-mono text-gray-700" dir="ltr">{{ $application->application_number }}</span></td>
                <td class="px-4 py-3">
                    <p class="font-medium text-gray-800 text-sm">{{ $application->user?->full_name ?: '—' }}</p>
                    <p class="text-[10px] text-gray-400" dir="ltr">{{ $application->user?->mobile ?: '—' }}</p>
                </td>
                <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-600">
                    {{ $application->reservation?->product?->title_fa ?: '—' }}
                </td>
                <td class="px-4 py-3">
                    <x-admin.status-badge :color="match(true) {
                        in_array($application->state->value, ['approved', 'active', 'returned', 'closed'], true) => 'green',
                        in_array($application->state->value, ['rejected', 'cancelled'], true) => 'red',
                        str_ends_with($application->state->value, '_pending') => 'yellow',
                        default => 'blue',
                    }" :label="$application->state->label()" />
                </td>
                <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500" dir="ltr">
                    @if($application->reservation)
                        {{ $application->reservation->start_date->format('Y/m/d') }} — {{ $application->reservation->end_date->format('Y/m/d') }}
                    @else
                        —
                    @endif
                </td>
                <td class="px-4 py-3 text-left">
                    <a href="{{ route('admin.rental-applications.show', $application) }}"
                       class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-brandBlue hover:bg-blue-50 transition-all" title="مشاهده">
                        <i class="fa-solid fa-eye text-xs"></i>
                    </a>
                </td>
            </tr>
            @endforeach
        </x-admin.table>
    @endif
</x-admin.panel>

@endsection
