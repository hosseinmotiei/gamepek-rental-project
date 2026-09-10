@extends('admin.layouts.app')

@section('title', 'سابقه تحویل دستگاه')
@section('page_badge', 'عملیات')
@section('breadcrumb') <a href="{{ route('admin.devices.show', $device) }}" class="text-gray-500 text-sm hover:text-gray-700">دستگاه</a> <span class="text-gray-300 mx-1">/</span> <span class="text-gray-700 text-sm">سابقه تحویل</span> @endsection

@section('content')
<div class="bg-white rounded-xl border border-gray-200 p-5 mb-5">
    <h1 class="text-lg font-black text-gray-800">{{ $device->product?->title_fa ?? '—' }}</h1>
    {{-- Masked serial. The raw value is not needed to read a custody trail. --}}
    <p class="text-xs text-gray-500 mt-1">سریال: <span class="font-mono" dir="ltr">{{ $device->maskedSerial() }}</span></p>

    <dl class="text-sm divide-y divide-gray-100 mt-5">
        <div class="flex justify-between py-2.5">
            <dt class="text-gray-500">مالک</dt>
            <dd class="text-gray-700 text-xs">{{ $device->ownerLabel() }}</dd>
        </div>
        <div class="flex justify-between py-2.5">
            <dt class="text-gray-500">نوع مالکیت</dt>
            <dd class="text-gray-700 text-xs">{{ $device->ownership->label() }}</dd>
        </div>
        <div class="flex justify-between py-2.5">
            <dt class="text-gray-500">اکنون در اختیار</dt>
            <dd class="text-gray-700 text-xs">{{ $currentCustody->label() }}</dd>
        </div>
    </dl>

    <p class="text-[11px] text-gray-400 mt-4 leading-6">
        «در اختیار» با «مالکیت» یکی نیست. سپردن دستگاه به گیم‌پک مالکیت آن را منتقل نمی‌کند و مالک ثبت‌شده تغییر نمی‌کند.
    </p>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-xs">
                <tr>
                    <th class="text-right font-medium px-4 py-3">عملیات</th>
                    <th class="text-right font-medium px-4 py-3">مسیر</th>
                    <th class="text-right font-medium px-4 py-3">وضعیت</th>
                    <th class="text-right font-medium px-4 py-3">درخواست</th>
                    <th class="text-right font-medium px-4 py-3">تحویل</th>
                    <th class="text-right font-medium px-4 py-3">تأیید مالک</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($transfers as $transfer)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            @if ($transfer->operation)
                                <a href="{{ route('admin.operations.show', $transfer->operation) }}"
                                   class="font-mono text-xs text-brandBlue hover:underline" dir="ltr">{{ $transfer->operation->operation_number }}</a>
                            @else
                                <span class="text-gray-400 text-xs">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600 text-xs">{{ $transfer->transfer_type->label() }}</td>
                        <td class="px-4 py-3 text-gray-600 text-xs">{{ $transfer->state->label() }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs" dir="ltr">{{ $transfer->initiated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs" dir="ltr">{{ $transfer->transferred_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs" dir="ltr">{{ $transfer->acknowledged_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-gray-400">
                            سابقه تحویلی برای این دستگاه ثبت نشده است.
                            @if ($device->isOwnedByGamePek())
                                این دستگاه متعلق به گیم‌پک است و از ابتدا در اختیار گیم‌پک بوده است.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
