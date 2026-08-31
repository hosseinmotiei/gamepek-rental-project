{{-- Server-side flash messages. Client-side feedback goes through
     adminToast() in the layout, not native alert(). --}}
{{-- Flash messages --}}
@if(session('success'))
<div class="mb-4 bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl flex items-center gap-2">
    <i class="fa-solid fa-circle-check"></i>
    {{ session('success') }}
</div>
@endif
@if(session('error'))
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl flex items-center gap-2">
    <i class="fa-solid fa-circle-exclamation"></i>
    {{ session('error') }}
</div>
@endif

