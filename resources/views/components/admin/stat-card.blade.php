{{--
    Dashboard stat tile. The Store dashboard hand-wrote six near-identical
    copies of this with no loop and no component.

    Usage: <x-admin.stat-card icon="fa-cart-shopping" color="blue"
                              label="سفارش‌های امروز" :value="persian_number($stats['orders_today'])" />
--}}
@props(['icon' => 'fa-chart-simple', 'color' => 'blue', 'label' => '', 'value' => '', 'suffix' => null])

@php
    $tones = [
        'blue'   => 'text-brandBlue bg-brandLightBlue',
        'green'  => 'text-green-600 bg-green-50',
        'red'    => 'text-red-600 bg-red-50',
        'yellow' => 'text-yellow-600 bg-yellow-50',
        'purple' => 'text-purple-600 bg-purple-50',
        'gray'   => 'text-gray-600 bg-gray-100',
    ];
    $tone = $tones[$color] ?? $tones['blue'];
@endphp

<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 md:p-5 flex items-center gap-4">
    <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 {{ $tone }}">
        <i class="fa-solid {{ $icon }}"></i>
    </div>
    <div class="min-w-0">
        <p class="text-[11px] text-gray-500 mb-1">{{ $label }}</p>
        <p class="text-lg font-black text-gray-800 truncate">
            {{ $value }}@if($suffix)<span class="text-[11px] font-normal text-gray-500 mr-1">{{ $suffix }}</span>@endif
        </p>
    </div>
</div>
