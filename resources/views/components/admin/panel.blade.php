{{--
    The standard white admin panel/card that wraps tables, filter bars and
    forms. Encodes the GamePek admin card recipe in one place:
    bg-white + rounded-2xl + border-gray-200 + shadow-sm.

    Usage: <x-admin.panel>…</x-admin.panel>
           <x-admin.panel :padded="true">…</x-admin.panel>
--}}
@props(['padded' => false])

<div {{ $attributes->merge(['class' => 'bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden' . ($padded ? ' p-4' : '')]) }}>
    {{ $slot }}
</div>
