{{--
    Scrollable admin table shell.

    Wide tables must scroll inside their own container rather than making the
    page scroll horizontally -- the Store repeated this overflow wrapper in
    every list view and occasionally forgot it, breaking mobile.

    Usage:
      <x-admin.table :paginator="$users">
          <x-slot:head><tr><th …>…</th></tr></x-slot:head>
          <tr>…</tr>
      </x-admin.table>
--}}
@props(['paginator' => null, 'head' => null])

<div class="overflow-x-auto admin-table-responsive">
    <table class="w-full text-sm">
        @if($head)
            <thead class="bg-gray-50 text-gray-500 text-xs">
                {{ $head }}
            </thead>
        @endif
        <tbody class="divide-y divide-gray-100">
            {{ $slot }}
        </tbody>
    </table>
</div>

@if($paginator && $paginator->hasPages())
    <div class="px-4 py-3 border-t border-gray-100">
        {{ $paginator->links() }}
    </div>
@endif
