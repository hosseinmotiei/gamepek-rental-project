@extends('admin.layouts.app')

@section('title', 'عملیات فیزیکی')
@section('page_badge', 'عملیات')

@section('content')
@php
    use App\Enums\RentalOperationState;

    $badge = fn (RentalOperationState $s) => match ($s) {
        RentalOperationState::Completed => 'bg-green-50 text-green-700 border-green-200',
        RentalOperationState::InProgress => 'bg-blue-50 text-blue-700 border-blue-200',
        RentalOperationState::Scheduled => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        RentalOperationState::AwaitingDeviceAllocation => 'bg-amber-50 text-amber-700 border-amber-200',
        RentalOperationState::Failed => 'bg-red-50 text-red-700 border-red-200',
        RentalOperationState::NotRequired => 'bg-gray-100 text-gray-600 border-gray-200',
        RentalOperationState::Pending => 'bg-gray-50 text-gray-500 border-gray-200',
    };
@endphp

<div class="mb-5">
    <h1 class="text-lg font-black text-gray-800">عملیات فیزیکی</h1>
    <p class="text-xs text-gray-500 mt-1">تحویل گرفتن از مالک، تحویل به مشتری، بازگشت از مشتری و بازگرداندن به مالک.</p>
    <a href="{{ route('admin.operations.reconciliation') }}" class="inline-block mt-2 text-xs text-brandBlue hover:underline">بررسی مغایرت‌های عملیات و تحویل</a>
</div>

<form method="GET" class="bg-white rounded-xl border border-gray-200 p-4 mb-5 flex flex-col md:flex-row md:flex-wrap md:items-center gap-3">
    <input aria-label="شماره عملیات" type="text" name="q" value="{{ request('q') }}" placeholder="شماره عملیات" dir="ltr"
           class="flex-1 md:min-w-[14rem] border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-mono placeholder:font-sans focus:outline-none focus:border-brandBlue">

    <select aria-label="وضعیت عملیات" name="state" class="border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
        <option value="">همه وضعیت‌ها</option>
        @foreach ($states as $state)
            <option value="{{ $state->value }}" @selected(request('state') === $state->value)>{{ $state->label() }}</option>
        @endforeach
    </select>

    <select aria-label="نوع عملیات" name="type" class="border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
        <option value="">همه انواع</option>
        @foreach ($types as $type)
            <option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->label() }}</option>
        @endforeach
    </select>

    <label class="flex items-center gap-2 text-sm text-gray-600 px-2">
        <input type="checkbox" name="open" value="1" @checked(request()->boolean('open'))>
        فقط باز
    </label>

    <button type="submit" class="bg-brandBlue text-white rounded-xl px-6 py-2.5 text-sm font-bold">فیلتر</button>
</form>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-xs">
                <tr>
                    <th class="text-right font-medium px-4 py-3">شماره عملیات</th>
                    <th class="text-right font-medium px-4 py-3">نوع</th>
                    <th class="text-right font-medium px-4 py-3">درخواست</th>
                    <th class="text-right font-medium px-4 py-3">دستگاه</th>
                    <th class="text-right font-medium px-4 py-3">مالک</th>
                    <th class="text-right font-medium px-4 py-3">مسئول</th>
                    <th class="text-right font-medium px-4 py-3">وضعیت</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($operations as $operation)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.operations.show', $operation) }}"
                               class="font-mono text-xs text-brandBlue hover:underline whitespace-nowrap" dir="ltr">{{ $operation->operation_number }}</a>
                        </td>
                        <td class="px-4 py-3 text-gray-600 text-xs">{{ $operation->type->label() }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-500 whitespace-nowrap" dir="ltr">{{ $operation->application?->application_number ?? '—' }}</td>
                        {{-- Masked: a raw serial does not belong on a list screen. --}}
                        <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap {{ $operation->hasDevice() ? 'font-mono' : '' }}" dir="{{ $operation->hasDevice() ? 'ltr' : 'rtl' }}">{{ $operation->deviceLabel() }}</td>
                        <td class="px-4 py-3 text-gray-600 text-xs">{{ $operation->owner?->displayName() ?? 'گیم‌پک' }}</td>
                        <td class="px-4 py-3 text-gray-600 text-xs">{{ $operation->assignedTo?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="text-[11px] border rounded-full px-3 py-1 whitespace-nowrap {{ $badge($operation->state) }}">{{ $operation->state->label() }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-gray-400">هیچ عملیاتی ثبت نشده است.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-5">{{ $operations->links() }}</div>
@endsection
