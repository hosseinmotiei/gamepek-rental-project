@extends('admin.layouts.app')

@section('title', 'احراز هویت')
@section('page_badge', 'احراز هویت')
@section('breadcrumb') <span class="text-gray-700 text-sm">احراز هویت</span> @endsection

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">صف احراز هویت (KYC سطح ۲)</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ persian_number($identities->total()) }} پرونده</p>
    </div>
</div>

<x-admin.filter-bar :action="route('admin.verifications.index')" :params="['search', 'state']" submit-label="جستجو">
    <div class="sm:col-span-2">
        <label class="block text-xs font-medium text-gray-600 mb-1.5">جستجو</label>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="نام یا موبایل..."
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
    @if($identities->isEmpty())
        <x-admin.empty-state icon="fa-id-card" message="پرونده احراز هویتی یافت نشد." />
    @else
        <x-admin.table :paginator="$identities">
            <x-slot:head>
                <tr class="border-b border-gray-100 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">کاربر</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">کد ملی</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">وضعیت</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">سطح</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">آخرین به‌روزرسانی</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs text-left">عملیات</th>
                </tr>
            </x-slot:head>

            @foreach($identities as $identity)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3">
                    <p class="font-medium text-gray-800 text-sm">{{ $identity->user?->full_name ?: '—' }}</p>
                    <p class="text-[10px] text-gray-400" dir="ltr">{{ $identity->user?->mobile ?: '—' }}</p>
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    {{-- Only the mask is ever listed; the real value stays encrypted. --}}
                    <span class="text-xs font-mono text-gray-600" dir="ltr">{{ $identity->national_code_mask ?: '—' }}</span>
                </td>
                <td class="px-4 py-3">
                    <x-admin.status-badge :color="match($identity->state->value) {
                        'verified' => 'green',
                        'failed', 'rejected' => 'red',
                        'manual_review' => 'yellow',
                        'checking' => 'blue',
                        default => 'gray',
                    }" :label="$identity->state->label()" />
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    <span class="text-sm font-bold text-gray-700">{{ persian_number($identity->kyc_level) }}</span>
                </td>
                <td class="px-4 py-3 hidden lg:table-cell">
                    <span class="text-xs text-gray-500">{{ $identity->updated_at?->format('Y/m/d H:i') ?: '—' }}</span>
                </td>
                <td class="px-4 py-3 text-left">
                    <a href="{{ route('admin.verifications.show', $identity) }}"
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
