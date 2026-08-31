{{--
    Status pill. The Store repeated this markup with hand-picked Tailwind
    colour classes in every list and detail view.

    Usage: <x-admin.status-badge color="green" label="فعال" />
--}}
@props(['color' => 'gray', 'label' => '', 'dot' => true])

@php
    // Full class strings, not interpolated fragments -- Tailwind's CDN JIT
    // only generates classes it can see literally in the markup.
    $styles = [
        'green'  => ['text-green-600 bg-green-50',   'bg-green-500'],
        'red'    => ['text-red-600 bg-red-50',       'bg-red-500'],
        'yellow' => ['text-yellow-700 bg-yellow-50', 'bg-yellow-500'],
        'blue'   => ['text-blue-600 bg-blue-50',     'bg-blue-500'],
        'purple' => ['text-purple-600 bg-purple-50', 'bg-purple-500'],
        'gray'   => ['text-gray-600 bg-gray-100',    'bg-gray-400'],
    ];
    [$pill, $dotColor] = $styles[$color] ?? $styles['gray'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 text-[10px] font-medium px-2 py-1 rounded-full $pill"]) }}>
    @if($dot)<span class="w-1.5 h-1.5 {{ $dotColor }} rounded-full"></span>@endif
    {{ $label !== '' ? $label : $slot }}
</span>
