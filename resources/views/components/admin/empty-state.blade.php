{{--
    Shared empty state for admin list views.

    In the Store this markup was copy-pasted into ~15 index views. Every list
    view needs one (see .claude/rules/admin-panel.md: an empty dataset must
    never crash or render a bare table).

    Usage: <x-admin.empty-state icon="fa-users" message="کاربری یافت نشد." />
--}}
@props(['icon' => 'fa-inbox', 'message' => 'موردی یافت نشد.'])

<div class="py-16 text-center text-gray-400">
    <i class="fa-solid {{ $icon }} text-4xl mb-3"></i>
    <p class="text-sm">{{ $message }}</p>
    @if(trim($slot ?? '') !== '')
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
