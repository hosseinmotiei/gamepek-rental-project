@extends('admin.layouts.app')

@section('title', 'پرونده احراز هویت')
@section('page_badge', 'احراز هویت')
@section('breadcrumb')
    <a href="{{ route('admin.verifications.index') }}" class="text-gray-500 hover:text-brandBlue text-sm">احراز هویت</a>
    <span class="text-gray-300 mx-1">/</span>
    <span class="text-gray-700 text-sm">{{ $identity->user?->full_name ?: 'پرونده' }}</span>
@endsection

@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <x-admin.panel padded class="lg:col-span-2">
        <h2 class="font-bold text-gray-800 text-sm mb-4">اطلاعات پرونده</h2>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-y-3 gap-x-6 text-sm">
            <div><dt class="text-xs text-gray-400">کاربر</dt><dd class="font-medium text-gray-800">{{ $identity->user?->full_name ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-400">موبایل</dt><dd class="font-mono text-gray-700" dir="ltr">{{ $identity->user?->mobile ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-400">کد ملی</dt><dd class="font-mono text-gray-700" dir="ltr">{{ $identity->national_code_mask ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-400">تاریخ تولد</dt><dd class="text-gray-700" dir="ltr">{{ $identity->birth_date?->format('Y-m-d') ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-400">نام ثبت احوال</dt><dd class="text-gray-700">{{ trim(($identity->registry_first_name ?? '').' '.($identity->registry_last_name ?? '')) ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-400">سطح KYC</dt><dd class="text-gray-700">{{ persian_number($identity->kyc_level) }}</dd></div>
            <div><dt class="text-xs text-gray-400">وضعیت</dt><dd><x-admin.status-badge :color="$identity->state->value === 'verified' ? 'green' : ($identity->state->value === 'manual_review' ? 'yellow' : 'gray')" :label="$identity->state->label()" /></dd></div>
            <div><dt class="text-xs text-gray-400">تأیید در</dt><dd class="text-gray-700">{{ $identity->verified_at?->format('Y/m/d H:i') ?: '—' }}</dd></div>
        </dl>

        @if($identity->rejection_reason)
            <p class="mt-4 text-xs text-red-600 bg-red-50 rounded-xl px-3 py-2">دلیل رد: {{ $identity->rejection_reason }}</p>
        @endif
    </x-admin.panel>

    @can('manage_verifications')
    <x-admin.panel padded>
        <h2 class="font-bold text-gray-800 text-sm mb-4">تصمیم دستی</h2>

        <form method="POST" action="{{ route('admin.verifications.approve', $identity) }}" class="mb-4"
              data-confirm="تأیید دستی هویت این کاربر؟ این اقدام در لاگ ممیزی ثبت می‌شود.">
            @csrf
            <textarea aria-label="یادداشت (اختیاری)" name="note" rows="2" placeholder="یادداشت (اختیاری)"
                      class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue mb-2"></textarea>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors">
                <i class="fa-solid fa-circle-check ml-1"></i> تأیید هویت
            </button>
        </form>

        <form method="POST" action="{{ route('admin.verifications.reject', $identity) }}"
              data-confirm="رد احراز هویت این کاربر؟">
            @csrf
            <textarea aria-label="دلیل رد (الزامی)" name="reason" rows="2" required placeholder="دلیل رد (الزامی)"
                      class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue mb-2"></textarea>
            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors">
                <i class="fa-solid fa-ban ml-1"></i> رد هویت
            </button>
        </form>
    </x-admin.panel>
    @endcan
</div>

<x-admin.panel class="mt-5">
    <div class="px-4 py-3 border-b border-gray-100">
        <h2 class="font-bold text-gray-800 text-sm">استعلام‌ها</h2>
    </div>
    @if($identity->verifications->isEmpty())
        <x-admin.empty-state icon="fa-magnifying-glass" message="هنوز استعلامی انجام نشده است." />
    @else
        <x-admin.table>
            <x-slot:head>
                <tr class="border-b border-gray-100 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">نوع</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">وضعیت</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">سرویس‌دهنده</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">امتیاز</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">زمان</th>
                </tr>
            </x-slot:head>
            @foreach($identity->verifications as $check)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-sm text-gray-800">{{ $check->type }}</td>
                <td class="px-4 py-3">
                    <x-admin.status-badge :color="match($check->state) {
                        'passed' => 'green', 'failed' => 'red', 'manual_review' => 'yellow', default => 'gray',
                    }" :label="$check->state" />
                </td>
                <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500">{{ $check->provider ?: '—' }}</td>
                <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500">{{ $check->score !== null ? persian_number($check->score) : '—' }}</td>
                <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-500">{{ $check->checked_at?->format('Y/m/d H:i') ?: '—' }}</td>
            </tr>
            @endforeach
        </x-admin.table>
    @endif
</x-admin.panel>

@endsection
