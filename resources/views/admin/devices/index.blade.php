@extends('admin.layouts.app')

@section('title', 'دستگاه‌های اجاره')

@section('content')
@php
    use App\Enums\DeviceState;

    $badge = fn (DeviceState $s) => match ($s) {
        DeviceState::Approved => 'bg-green-50 text-green-700 border-green-200',
        DeviceState::PendingReview => 'bg-amber-50 text-amber-700 border-amber-200',
        DeviceState::Rejected => 'bg-red-50 text-red-700 border-red-200',
        DeviceState::Disabled => 'bg-gray-100 text-gray-600 border-gray-200',
        DeviceState::Draft => 'bg-gray-50 text-gray-500 border-gray-200',
    };
@endphp

<div class="mb-5">
    <h1 class="text-lg font-black text-gray-800">دستگاه‌های اجاره</h1>
    <p class="text-xs text-gray-500 mt-1">ناوگان ترکیبی: دستگاه‌های گیم‌پک و دستگاه‌های مالکان شخص ثالث.</p>
</div>

<form method="GET" class="bg-white rounded-xl border border-gray-200 p-4 mb-5 flex flex-col md:flex-row gap-3">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="جستجوی شماره سریال" dir="ltr"
           class="flex-1 border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-brandBlue">

    <select name="state" class="border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
        <option value="">همه وضعیت‌ها</option>
        @foreach ($states as $state)
            <option value="{{ $state->value }}" @selected(request('state') === $state->value)>{{ $state->label() }}</option>
        @endforeach
    </select>

    <select name="ownership" class="border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
        <option value="">همه مالکیت‌ها</option>
        @foreach ($ownerships as $ownership)
            <option value="{{ $ownership->value }}" @selected(request('ownership') === $ownership->value)>{{ $ownership->label() }}</option>
        @endforeach
    </select>

    <button type="submit" class="bg-brandBlue text-white rounded-xl px-5 py-2.5 text-sm font-bold hover:bg-blue-700 transition-colors">فیلتر</button>
</form>

@if ($devices->isEmpty())
    <div class="bg-white rounded-xl border border-gray-200 p-10 text-center">
        <p class="text-sm text-gray-500">دستگاهی یافت نشد.</p>
    </div>
@else
    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
            <thead class="bg-gray-50 text-gray-500 text-xs">
                <tr>
                    <th class="px-4 py-3 text-right font-medium">محصول</th>
                    <th class="px-4 py-3 text-right font-medium">شماره سریال</th>
                    <th class="px-4 py-3 text-right font-medium">مالکیت</th>
                    <th class="px-4 py-3 text-right font-medium">وضعیت</th>
                    <th class="px-4 py-3 text-right font-medium">بررسی</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($devices as $device)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-800">{{ $device->product?->title_fa ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-gray-600" dir="ltr">{{ $device->maskedSerial() }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $device->ownerLabel() }}</td>
                        <td class="px-4 py-3">
                            <span class="text-[11px] border rounded-full px-2.5 py-1 {{ $badge($device->state) }}">{{ $device->state->label() }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $device->verification_state->label() }}</td>
                        <td class="px-4 py-3 text-left">
                            <a href="{{ route('admin.devices.show', $device) }}" class="text-brandBlue text-xs font-bold hover:underline">جزئیات</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-5">{{ $devices->links() }}</div>
@endif
@endsection
