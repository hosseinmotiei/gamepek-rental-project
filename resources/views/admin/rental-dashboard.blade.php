@extends('admin.layouts.app')

@section('title', 'داشبورد اجاره')
@section('page_badge', 'اجاره')
@section('breadcrumb') <span class="text-gray-700 text-sm">داشبورد اجاره</span> @endsection

@section('content')

@php
    $apps = $metrics['applications'];
    $ops = $metrics['operations'];
    $closeout = $metrics['closeout'];
    $damage = $metrics['damage'];
    $notes = $metrics['notes'];
    $settlement = $metrics['settlement'];
    $fleet = $metrics['fleet'];
    $late = $metrics['late'];
    $integrity = $metrics['integrity'];
    $toman = fn (int $amount) => persian_number(number_format($amount)).' تومان';
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">داشبورد اجاره</h1>
        <p class="text-xs text-gray-500 mt-0.5">وضعیت عملیاتی امروز — فقط گزارش؛ هیچ اقدامی از این صفحه ثبت نمی‌شود.</p>
    </div>
    <a href="{{ route('admin.rental-applications.index') }}" class="text-xs font-bold text-brandBlue hover:underline">
        همه درخواست‌ها <i class="fa-solid fa-arrow-left mr-1"></i>
    </a>
</div>

{{-- ─── کارهای در انتظار اقدام ───────────────────────────────────────── --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    @php
        $tiles = [
            ['label' => 'در انتظار تأیید نهایی', 'value' => $apps['awaiting_approval'], 'icon' => 'fa-user-check', 'tone' => 'amber'],
            ['label' => 'عملیات باز', 'value' => $ops['open_total'], 'icon' => 'fa-truck', 'tone' => 'blue'],
            ['label' => 'در انتظار تخصیص دستگاه', 'value' => $ops['awaiting_allocation'], 'icon' => 'fa-gamepad', 'tone' => 'amber'],
            ['label' => 'اجاره‌های با تأخیر', 'value' => $late['overdue_count'], 'icon' => 'fa-clock', 'tone' => 'red'],
        ];
    @endphp
    @foreach ($tiles as $tile)
        <x-admin.panel padded>
            <div class="flex items-center justify-between gap-2">
                <div>
                    <p class="text-[11px] text-gray-500 leading-5">{{ $tile['label'] }}</p>
                    <p class="text-xl font-bold text-gray-800 mt-1">{{ persian_number($tile['value']) }}</p>
                </div>
                <span class="w-9 h-9 shrink-0 rounded-xl flex items-center justify-center
                    {{ ['amber' => 'bg-amber-50 text-amber-600', 'blue' => 'bg-blue-50 text-blue-600', 'red' => 'bg-red-50 text-red-600'][$tile['tone']] }}">
                    <i class="fa-solid {{ $tile['icon'] }}"></i>
                </span>
            </div>
        </x-admin.panel>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
    {{-- ─── چرخه اجاره ───────────────────────────────────────────────── --}}
    <x-admin.panel padded>
        <h2 class="font-bold text-gray-800 text-sm mb-3">چرخه اجاره</h2>
        <dl class="text-xs divide-y divide-gray-100">
            @foreach ([
                'در جریان (پیش از تأیید)' => $apps['in_progress'],
                'تأییدشده — تحویل نشده' => $apps['approved'],
                'در حال اجاره' => $apps['active'],
                'بازگشته — در انتظار بستن' => $apps['returned'],
                'بسته‌شده' => $apps['closed'],
                'لغو یا رد شده' => $apps['cancelled'],
            ] as $label => $value)
                <div class="py-2 flex items-center justify-between gap-3">
                    <dt class="text-gray-600">{{ $label }}</dt>
                    <dd class="font-bold text-gray-800">{{ persian_number($value) }}</dd>
                </div>
            @endforeach
        </dl>
    </x-admin.panel>

    {{-- ─── عملیات فیزیکی ────────────────────────────────────────────── --}}
    <x-admin.panel padded>
        <h2 class="font-bold text-gray-800 text-sm mb-3">عملیات باز</h2>
        <dl class="text-xs divide-y divide-gray-100">
            @foreach ([
                'دریافت دستگاه از مالک' => $ops['by_type']['owner_pickup'],
                'تحویل به مشتری' => $ops['by_type']['delivery'],
                'دریافت از مشتری' => $ops['by_type']['customer_return'],
                'بازگرداندن به مالک' => $ops['by_type']['owner_return'],
                'ناموفق' => $ops['failed'],
            ] as $label => $value)
                <div class="py-2 flex items-center justify-between gap-3">
                    <dt class="text-gray-600">{{ $label }}</dt>
                    <dd class="font-bold text-gray-800">{{ persian_number($value) }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="text-[11px] text-gray-400 mt-2 leading-6">تخصیص دستگاه انتخاب انسان است؛ هیچ دستگاهی خودکار انتخاب نمی‌شود.</p>
    </x-admin.panel>

    {{-- ─── بستن اجاره ───────────────────────────────────────────────── --}}
    <x-admin.panel padded>
        <h2 class="font-bold text-gray-800 text-sm mb-3">پیش‌نیازهای بستن اجاره</h2>
        <dl class="text-xs divide-y divide-gray-100">
            @foreach ([
                'بازگشته (مجموع)' => $closeout['returned_total'],
                'بدون بازرسی بازگشت' => $closeout['missing_return_inspection'],
                'خسارت تعیین‌تکلیف نشده' => $closeout['unresolved_damage'],
                'سفته تعیین‌تکلیف نشده' => $closeout['unresolved_note'],
                'در انتظار بازگرداندن به مالک' => $closeout['awaiting_owner_return'],
            ] as $label => $value)
                <div class="py-2 flex items-center justify-between gap-3">
                    <dt class="text-gray-600">{{ $label }}</dt>
                    <dd class="font-bold text-gray-800">{{ persian_number($value) }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="text-[11px] text-gray-400 mt-2 leading-6">اجاره هرگز خودکار بسته نمی‌شود.</p>
    </x-admin.panel>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
    {{-- ─── خسارت ────────────────────────────────────────────────────── --}}
    <x-admin.panel padded>
        <h2 class="font-bold text-gray-800 text-sm mb-1">خسارت</h2>
        <p class="text-[11px] text-gray-400 mb-3 leading-6">«تعیین‌شده» بدهی ثبت‌شده نیست؛ مهلتی برای پرداخت تعریف نشده است.</p>
        <dl class="text-xs divide-y divide-gray-100">
            <div class="py-2 flex items-center justify-between gap-3">
                <dt class="text-gray-600">تعیین‌شده (پرداخت‌نشده)</dt>
                <dd class="font-bold text-gray-800">{{ persian_number($damage['unpaid_count']) }} — {{ $toman($damage['unpaid_amount']) }}</dd>
            </div>
            <div class="py-2 flex items-center justify-between gap-3">
                <dt class="text-gray-600">دریافت‌شده به کیف پول گیم‌پک</dt>
                <dd class="font-bold text-gray-800">{{ persian_number($damage['received_count']) }} — {{ $toman($damage['received_amount']) }}</dd>
            </div>
        </dl>
    </x-admin.panel>

    {{-- ─── سفته ─────────────────────────────────────────────────────── --}}
    <x-admin.panel padded>
        <h2 class="font-bold text-gray-800 text-sm mb-1">سفته‌ها</h2>
        <p class="text-[11px] text-gray-400 mb-3 leading-6">سفته پول نیست و در تسویه محاسبه نمی‌شود.</p>
        <dl class="text-xs divide-y divide-gray-100">
            @foreach ([
                'نزد گیم‌پک (در جریان)' => $notes['held'],
                'نگه‌داشته‌شده بابت خسارت پرداخت‌نشده' => $notes['retained'],
                'تحویل‌شده به مالک' => $notes['transferred'],
                'بازگردانده‌شده به مشتری' => $notes['returned'],
            ] as $label => $value)
                <div class="py-2 flex items-center justify-between gap-3">
                    <dt class="text-gray-600">{{ $label }}</dt>
                    <dd class="font-bold text-gray-800">{{ persian_number($value) }}</dd>
                </div>
            @endforeach
        </dl>
        @if ($notes['retained'] > 0)
            <p class="text-[11px] text-amber-700 mt-2 leading-6">سفته نگه‌داشته‌شده همچنان قابل تعیین تکلیف است: با پرداخت خسارت، حتی پس از بسته‌شدن اجاره، به مشتری بازگردانده می‌شود.</p>
        @endif
    </x-admin.panel>

    {{-- ─── تسویه مالک ───────────────────────────────────────────────── --}}
    <x-admin.panel padded>
        <h2 class="font-bold text-gray-800 text-sm mb-1">تسویه مالک</h2>
        <p class="text-[11px] text-gray-400 mb-3 leading-6">«محاسبه‌شده» با «واریزشده» یکی نیست. تسویه دستی است و زمان‌بندی خودکار ندارد.</p>
        <dl class="text-xs divide-y divide-gray-100">
            <div class="py-2 flex items-center justify-between gap-3">
                <dt class="text-gray-600">محاسبه‌شده — واریز نشده</dt>
                <dd class="font-bold text-gray-800">{{ persian_number($settlement['awaiting_credit_count']) }} — {{ $toman($settlement['awaiting_credit_amount']) }}</dd>
            </div>
            <div class="py-2 flex items-center justify-between gap-3">
                <dt class="text-gray-600">واریزشده به کیف پول مالک</dt>
                <dd class="font-bold text-gray-800">{{ persian_number($settlement['credited_count']) }} — {{ $toman($settlement['credited_amount']) }}</dd>
            </div>
        </dl>
    </x-admin.panel>
</div>

{{-- ─── ناوگان و ظرفیت ───────────────────────────────────────────────── --}}
<x-admin.panel padded class="mb-5">
    <h2 class="font-bold text-gray-800 text-sm mb-3">ناوگان دستگاه‌ها</h2>
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-xs">
        @foreach ([
            'قابل اجاره' => $fleet['rentable'],
            'نزد مشتری' => $fleet['with_customers'],
            'در انتظار تأیید' => $fleet['pending_approval'],
            'غیرفعال' => $fleet['disabled'],
            'مدل بدون دستگاه' => $fleet['products_without_devices'],
        ] as $label => $value)
            <div class="rounded-xl bg-gray-50 p-3">
                <p class="text-gray-500 leading-5">{{ $label }}</p>
                <p class="text-base font-bold text-gray-800 mt-1">{{ persian_number($value) }}</p>
            </div>
        @endforeach
    </div>
    <p class="text-[11px] text-gray-400 mt-3 leading-6">
        ظرفیت هر مدل برابر دستگاه‌های فیزیکی قابل اجاره آن است. وقتی همه دستگاه‌ها مشغول باشند، رزرو پذیرفته نمی‌شود؛ فهرست انتظار وجود ندارد.
    </p>
</x-admin.panel>

{{-- ─── اجاره‌های با تأخیر ───────────────────────────────────────────── --}}
<x-admin.panel padded class="mb-5">
    <h2 class="font-bold text-gray-800 text-sm mb-1">اجاره‌های با تأخیر</h2>
    <p class="text-[11px] text-gray-400 mb-3 leading-6">
        تا بازگشت فیزیکی دستگاه، آن دستگاه برای هیچ رزرو دیگری آزاد نمی‌شود.
        مبلغ تأخیر فقط محاسبه‌شده است و دریافت‌کننده آن هنوز تعیین نشده؛ در تسویه وارد نمی‌شود.
    </p>
    @if ($late['queue']->isEmpty())
        <x-admin.empty-state icon="fa-clock" message="هیچ اجاره‌ای با تأخیر نیست." />
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead><tr class="border-b border-gray-100 text-right text-gray-500">
                    <th class="px-3 py-2 font-semibold">درخواست</th>
                    <th class="px-3 py-2 font-semibold">مدل</th>
                    <th class="px-3 py-2 font-semibold">پایان قرارداد</th>
                    <th class="px-3 py-2 font-semibold">روزهای تأخیر</th>
                </tr></thead>
                <tbody>
                @foreach ($late['queue'] as $reservation)
                    @php $lateReturn = $reservation->lateReturn(); @endphp
                    <tr class="border-b border-gray-50">
                        <td class="px-3 py-2">
                            <a href="{{ route('admin.rental-applications.show', $reservation->application) }}" class="text-brandBlue hover:underline">
                                {{ persian_number($reservation->application->application_number) }}
                            </a>
                        </td>
                        <td class="px-3 py-2 text-gray-700">{{ $reservation->product?->title_fa ?? '—' }}</td>
                        <td class="px-3 py-2 text-gray-700">{{ persian_number($reservation->end_date->toDateString()) }}</td>
                        <td class="px-3 py-2 font-bold text-red-600">{{ persian_number($lateReturn->lateDays) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-admin.panel>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    {{-- ─── مغایرت‌ها ────────────────────────────────────────────────── --}}
    <x-admin.panel padded>
        <h2 class="font-bold text-gray-800 text-sm mb-1">مغایرت‌های ثبت‌شده</h2>
        <p class="text-[11px] text-gray-400 mb-3 leading-6">فقط گزارش است؛ هیچ مغایرتی خودکار اصلاح نمی‌شود.</p>
        @if (($integrity['total'] ?? 0) === 0)
            <x-admin.empty-state icon="fa-shield-halved" message="هیچ مغایرتی یافت نشد." />
        @else
            <ul class="text-xs divide-y divide-gray-100">
                @foreach ($integrity['by_code'] as $code => $count)
                    <li class="py-2 flex items-start justify-between gap-3">
                        <span class="text-gray-700">{{ app(App\Services\Rental\OperationCustodyReconciler::class)->label($code) }}</span>
                        <span class="font-bold text-amber-700 shrink-0">{{ persian_number($count) }}</span>
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('admin.operations.reconciliation') }}" class="inline-block mt-3 text-xs font-bold text-brandBlue hover:underline">مشاهده جزئیات</a>
        @endif
    </x-admin.panel>

    {{-- ─── آخرین جابه‌جایی‌ها ───────────────────────────────────────── --}}
    <x-admin.panel padded>
        <h2 class="font-bold text-gray-800 text-sm mb-3">آخرین عملیات تکمیل‌شده</h2>
        @if ($metrics['recent_operations']->isEmpty())
            <x-admin.empty-state icon="fa-clock-rotate-left" message="هنوز عملیاتی تکمیل نشده است." />
        @else
            <ul class="text-xs divide-y divide-gray-100">
                @foreach ($metrics['recent_operations'] as $operation)
                    <li class="py-2 flex items-start justify-between gap-3">
                        <div>
                            <p class="text-gray-700">{{ $operation->type->label() }}</p>
                            <p class="text-[11px] text-gray-400 mt-0.5">
                                {{ $operation->deviceLabel() }}
                                @if ($operation->completedBy) — {{ $operation->completedBy->full_name }} @endif
                            </p>
                        </div>
                        <span class="text-[11px] text-gray-400 shrink-0">
                            {{ $operation->completed_at ? persian_number($operation->completed_at->format('Y-m-d H:i')) : '—' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>
</div>

@endsection
