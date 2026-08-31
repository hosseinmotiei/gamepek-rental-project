{{--
    Filter/search bar above an admin list. Renders the GET form, the panel
    chrome, the submit button and the conditional reset link that the Store
    repeated by hand in every index view.

    Usage:
      <x-admin.filter-bar :action="route('admin.users.index')" :params="['search','status']">
          <input name="search" …>
          <select name="status">…</select>
      </x-admin.filter-bar>

    $params lists the query keys this bar owns; the reset link only appears
    when at least one of them is currently set.
--}}
@props(['action', 'params' => [], 'submitLabel' => 'اعمال فیلتر', 'resetLabel' => 'حذف فیلترها'])

<form method="GET" action="{{ $action }}" class="bg-white rounded-2xl border border-gray-200 p-4 mb-5 shadow-sm">
    <div class="flex flex-col md:flex-row md:items-end gap-3">
        <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            {{ $slot }}
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button type="submit" class="bg-brandBlue hover:bg-blue-600 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors">
                <i class="fa-solid fa-filter ml-1"></i> {{ $submitLabel }}
            </button>
            @if(request()->hasAny($params))
                <a href="{{ $action }}" class="text-xs font-bold text-gray-500 hover:text-brandBlue px-3 py-2.5 transition-colors">
                    {{ $resetLabel }}
                </a>
            @endif
        </div>
    </div>
</form>
