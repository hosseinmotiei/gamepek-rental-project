@extends('admin.layouts.app')

@section('title', 'درخواست اجاره')
@section('page_badge', 'اجاره')
@section('breadcrumb')
    <a href="{{ route('admin.rental-applications.index') }}" class="text-gray-500 hover:text-brandBlue text-sm">درخواست‌های اجاره</a>
    <span class="text-gray-300 mx-1">/</span>
    <span class="text-gray-700 text-sm" dir="ltr">{{ $application->application_number }}</span>
@endsection

@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <x-admin.panel padded class="lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-bold text-gray-800 text-sm">خلاصه درخواست</h2>
            <x-admin.status-badge :color="match(true) {
                $application->state->value === 'approved' => 'green',
                in_array($application->state->value, ['rejected', 'cancelled'], true) => 'red',
                default => 'blue',
            }" :label="$application->state->label()" />
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-y-3 gap-x-6 text-sm">
            <div><dt class="text-xs text-gray-400">کاربر</dt><dd class="font-medium text-gray-800">{{ $application->user?->full_name ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-400">موبایل</dt><dd class="font-mono text-gray-700" dir="ltr">{{ $application->user?->mobile ?: '—' }}</dd></div>
            <div>
                <dt class="text-xs text-gray-400">وضعیت هویت</dt>
                <dd class="text-gray-700">
                    {{ $application->user?->identity?->state->label() ?? 'ثبت نشده' }}
                    @if($application->user?->identity)
                        <a href="{{ route('admin.verifications.show', $application->user->identity) }}" class="text-brandBlue text-xs">(پرونده)</a>
                    @endif
                </dd>
            </div>
            <div><dt class="text-xs text-gray-400">سفارش</dt><dd class="text-gray-700" dir="ltr">{{ $application->order?->order_number ?: '—' }}</dd></div>
        </dl>
    </x-admin.panel>

    @can('manage_rental_applications')
    <x-admin.panel padded>
        <h2 class="font-bold text-gray-800 text-sm mb-4">تصمیم نهایی</h2>

        {{-- Re-derives the state from the child facts; never forces a target. --}}
        <form method="POST" action="{{ route('admin.rental-applications.refresh', $application) }}" class="mb-4">
            @csrf
            <button type="submit" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold px-4 py-2.5 rounded-xl transition-colors">
                <i class="fa-solid fa-rotate ml-1"></i> بازخوانی وضعیت
            </button>
        </form>

        <form method="POST" action="{{ route('admin.rental-applications.approve', $application) }}" class="mb-4"
              data-confirm="تأیید نهایی این درخواست اجاره؟">
            @csrf
            <textarea name="note" rows="2" placeholder="یادداشت (اختیاری)"
                      class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue mb-2"></textarea>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors">
                <i class="fa-solid fa-circle-check ml-1"></i> تأیید نهایی
            </button>
        </form>

        <form method="POST" action="{{ route('admin.rental-applications.reject', $application) }}"
              data-confirm="رد این درخواست اجاره؟">
            @csrf
            <textarea name="reason" rows="2" required placeholder="دلیل رد (الزامی)"
                      class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue mb-2"></textarea>
            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors">
                <i class="fa-solid fa-ban ml-1"></i> رد درخواست
            </button>
        </form>
    </x-admin.panel>
    @endcan
</div>

<x-admin.panel padded class="mt-5">
    <h2 class="font-bold text-gray-800 text-sm mb-4">رزرو</h2>
    @if(! $application->reservation)
        <p class="text-xs text-gray-400">هنوز رزروی ثبت نشده است.</p>
    @else
        @php($reservation = $application->reservation)
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-y-3 gap-x-6 text-sm">
            <div><dt class="text-xs text-gray-400">دستگاه</dt><dd class="text-gray-800">{{ $reservation->product?->title_fa ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-400">بازه</dt><dd class="text-gray-700" dir="ltr">{{ $reservation->start_date->format('Y/m/d') }} — {{ $reservation->end_date->format('Y/m/d') }}</dd></div>
            <div><dt class="text-xs text-gray-400">مدت</dt><dd class="text-gray-700">{{ persian_number($reservation->days) }} روز</dd></div>
            <div><dt class="text-xs text-gray-400">وضعیت</dt><dd><x-admin.status-badge color="gray" :label="$reservation->state->value" /></dd></div>
            <div><dt class="text-xs text-gray-400">اجاره‌بها</dt><dd class="text-gray-700">{{ persian_number(number_format($reservation->rental_total)) }} تومان</dd></div>
            <div><dt class="text-xs text-gray-400">پرداختی</dt><dd class="font-bold text-gray-800">{{ persian_number(number_format($reservation->payable_now)) }} تومان</dd></div>
            {{-- Deposit is blocked, never charged — it is not part of payable_now. --}}
            <div><dt class="text-xs text-gray-400">ودیعه (بلوکه)</dt><dd class="text-gray-700">{{ persian_number(number_format($reservation->deposit_amount)) }} تومان</dd></div>
        </dl>
    @endif
</x-admin.panel>

<x-admin.panel padded class="mt-5">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-bold text-gray-800 text-sm">ضمانت</h2>
        @if($application->guarantee)
            <x-admin.status-badge :color="$application->guarantee->state->value === 'verified' ? 'green' : 'gray'" :label="$application->guarantee->state->value" />
        @endif
    </div>

    @if(! $application->guarantee)
        <p class="text-xs text-gray-400">هنوز ضمانتی ثبت نشده است.</p>
    @else
        @php($guarantee = $application->guarantee)
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-y-3 gap-x-6 text-sm mb-4">
            <div><dt class="text-xs text-gray-400">نوع</dt><dd class="text-gray-800">{{ $guarantee->type }}</dd></div>
            <div><dt class="text-xs text-gray-400">شناسه صیاد</dt><dd class="font-mono text-gray-700" dir="ltr">{{ $guarantee->sayad_id ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-400">مبلغ</dt><dd class="text-gray-700">{{ $guarantee->amount ? persian_number(number_format($guarantee->amount)).' تومان' : '—' }}</dd></div>
            <div><dt class="text-xs text-gray-400">تطابق مالکیت</dt><dd class="text-gray-700">{{ $guarantee->ownership_match === null ? 'نامشخص' : ($guarantee->ownership_match ? 'منطبق' : 'مغایر') }}</dd></div>
        </dl>

        @if($guarantee->inquiries->isNotEmpty())
        <div class="overflow-x-auto admin-table-responsive mb-4">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs">
                    <tr class="text-right">
                        <th class="px-3 py-2 font-semibold">استعلام</th>
                        <th class="px-3 py-2 font-semibold">نتیجه</th>
                        <th class="px-3 py-2 font-semibold hidden md:table-cell">سرویس‌دهنده</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($guarantee->inquiries as $inquiry)
                    <tr>
                        <td class="px-3 py-2 text-gray-800">{{ $inquiry->kind }}</td>
                        <td class="px-3 py-2">
                            <x-admin.status-badge :color="match($inquiry->result) { 'pass' => 'green', 'fail' => 'red', default => 'gray' }"
                                                  :label="$inquiry->result ?: 'نامشخص'" />
                        </td>
                        <td class="px-3 py-2 hidden md:table-cell text-xs text-gray-500">{{ $inquiry->provider ?: '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @can('manage_guarantees')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <form method="POST" action="{{ route('admin.rental-applications.guarantee.verify', $application) }}"
                  data-confirm="تأیید دستی ضمانت؟">
                @csrf
                <input type="text" name="note" placeholder="یادداشت (اختیاری)"
                       class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue mb-2">
                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors">تأیید ضمانت</button>
            </form>
            <form method="POST" action="{{ route('admin.rental-applications.guarantee.reject', $application) }}"
                  data-confirm="رد ضمانت؟">
                @csrf
                <input type="text" name="reason" required placeholder="دلیل رد (الزامی)"
                       class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue mb-2">
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors">رد ضمانت</button>
            </form>
        </div>
        @endcan
    @endif
</x-admin.panel>

<x-admin.panel padded class="mt-5">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-bold text-gray-800 text-sm">قرارداد</h2>
        @if($application->contract)
            <x-admin.status-badge :color="match($application->contract->state->value) { 'signed' => 'green', 'void' => 'red', default => 'gray' }"
                                  :label="$application->contract->state->value" />
        @endif
    </div>

    @if(! $application->contract)
        <p class="text-xs text-gray-400">هنوز قراردادی صادر نشده است.</p>
    @else
        @php($contract = $application->contract)
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-y-3 gap-x-6 text-sm mb-4">
            <div><dt class="text-xs text-gray-400">شماره</dt><dd class="font-mono text-gray-700" dir="ltr">{{ $contract->number }}</dd></div>
            <div><dt class="text-xs text-gray-400">قالب</dt><dd class="text-gray-700" dir="ltr">{{ $contract->template_key }} v{{ $contract->template_version }}</dd></div>
            <div><dt class="text-xs text-gray-400">پذیرش</dt><dd class="text-gray-700">{{ $contract->accepted_at?->format('Y/m/d H:i') ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-400">امضا</dt><dd class="text-gray-700">{{ $contract->signed_at?->format('Y/m/d H:i') ?: '—' }}</dd></div>
            <div class="col-span-2 md:col-span-4">
                <dt class="text-xs text-gray-400 mb-1">یکپارچگی متن</dt>
                <dd>
                    @if($contract->isIntact())
                        <x-admin.status-badge color="green" label="سالم — هش متن با هش ثبت‌شده منطبق است" />
                    @else
                        <x-admin.status-badge color="red" label="مغایر — متن قرارداد پس از صدور تغییر کرده است" />
                    @endif
                </dd>
            </div>
        </dl>

        @if($contract->signatures->isNotEmpty())
        <ul class="text-xs text-gray-600 space-y-1 mb-4">
            @foreach($contract->signatures as $signature)
                <li dir="ltr">{{ $signature->method }} — {{ $signature->signed_at?->format('Y/m/d H:i') }} — {{ $signature->ip_address }}</li>
            @endforeach
        </ul>
        @endif

        @can('manage_contracts')
        @if($contract->state->value !== 'void')
        <form method="POST" action="{{ route('admin.rental-applications.contract.void', $application) }}"
              data-confirm="ابطال قرارداد؟ این اقدام برگشت‌پذیر نیست.">
            @csrf
            <input type="text" name="reason" required placeholder="دلیل ابطال (الزامی)"
                   class="w-full md:w-1/2 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue mb-2">
            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors">ابطال قرارداد</button>
        </form>
        @endif
        @endcan
    @endif
</x-admin.panel>

<x-admin.panel class="mt-5">
    <div class="px-4 py-3 border-b border-gray-100">
        <h2 class="font-bold text-gray-800 text-sm">تاریخچه وضعیت</h2>
    </div>
    @if($application->transitions->isEmpty())
        <x-admin.empty-state icon="fa-timeline" message="تغییر وضعیتی ثبت نشده است." />
    @else
        <x-admin.table>
            <x-slot:head>
                <tr class="border-b border-gray-100 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">از</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">به</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">دلیل</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">شناسه همبستگی</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">زمان</th>
                </tr>
            </x-slot:head>
            @foreach($application->transitions as $transition)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-xs text-gray-500">{{ $transition->from_state ?: '—' }}</td>
                <td class="px-4 py-3 text-xs font-medium text-gray-800">{{ $transition->to_state }}</td>
                <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500">{{ $transition->reason ?: '—' }}</td>
                <td class="px-4 py-3 hidden lg:table-cell">
                    {{-- Jumps to every audit row written in the same request. --}}
                    <a href="{{ route('admin.audit-events.index', ['correlation_id' => $transition->correlation_id]) }}"
                       class="text-[10px] font-mono text-brandBlue" dir="ltr">{{ $transition->correlation_id }}</a>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500">{{ $transition->created_at?->format('Y/m/d H:i') }}</td>
            </tr>
            @endforeach
        </x-admin.table>
    @endif
</x-admin.panel>

@endsection
