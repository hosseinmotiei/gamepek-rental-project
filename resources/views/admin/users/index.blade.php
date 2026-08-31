{{--
    Reference implementation for admin list views.

    The Store repeated the filter form, table shell, status pill, empty state
    and pagination markup by hand in ~15 index views. This one is built from
    the shared components in resources/views/components/admin/ — copy this
    shape for new admin lists rather than the Store's inline markup.
--}}
@extends('admin.layouts.app')

@section('title', 'کاربران')
@section('page_badge', 'کاربران')
@section('breadcrumb') <span class="text-gray-700 text-sm">کاربران</span> @endsection

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">مدیریت کاربران</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ persian_number($users->total()) }} کاربر</p>
    </div>
</div>

<x-admin.filter-bar :action="route('admin.users.index')" :params="['search', 'status']" submit-label="جستجو">
    <div class="sm:col-span-2">
        <label class="block text-xs font-medium text-gray-600 mb-1.5">جستجو</label>
        <input type="text" name="search" value="{{ request('search') }}"
               placeholder="نام، موبایل یا ایمیل..."
               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all">
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1.5">وضعیت</label>
        <select name="status" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brandBlue">
            <option value="">همه</option>
            <option value="active"  {{ request('status') === 'active'  ? 'selected' : '' }}>فعال</option>
            <option value="blocked" {{ request('status') === 'blocked' ? 'selected' : '' }}>مسدود</option>
        </select>
    </div>
</x-admin.filter-bar>

<x-admin.panel>
    @if($users->isEmpty())
        <x-admin.empty-state icon="fa-users" message="کاربری یافت نشد." />
    @else
        <x-admin.table :paginator="$users">
            <x-slot:head>
                <tr class="border-b border-gray-100 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">کاربر</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">موبایل</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">ایمیل</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">وضعیت</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">سفارش‌ها</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">آخرین ورود</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs text-left">عملیات</th>
                </tr>
            </x-slot:head>

            @foreach($users as $user)
            <tr class="hover:bg-gray-50 transition-colors {{ $user->status === 'blocked' ? 'opacity-70' : '' }}">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-brandBlue/10 flex items-center justify-center shrink-0">
                            @if($user->avatar)
                            <img src="{{ media_url($user->avatar) }}" alt="" class="w-full h-full object-cover rounded-full">
                            @else
                            <i class="fa-solid fa-user text-brandBlue text-xs"></i>
                            @endif
                        </div>
                        <div>
                            <p class="font-medium text-gray-800 text-sm">{{ $user->full_name ?: '—' }}</p>
                            <p class="text-[10px] text-gray-400">عضویت: {{ $user->created_at->format('Y/m/d') }}</p>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    <span class="text-sm font-mono text-gray-700" dir="ltr">{{ $user->mobile }}</span>
                </td>
                <td class="px-4 py-3 hidden lg:table-cell">
                    <span class="text-xs text-gray-500" dir="ltr">{{ $user->email ?: '—' }}</span>
                </td>
                <td class="px-4 py-3">
                    @if($user->status === 'active')
                        <x-admin.status-badge color="green" label="فعال" />
                    @else
                        <x-admin.status-badge color="red" label="مسدود" />
                    @endif
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    <span class="font-bold text-gray-700">{{ persian_number($user->orders_count) }}</span>
                </td>
                <td class="px-4 py-3 hidden lg:table-cell">
                    <span class="text-xs text-gray-500">
                        {{ $user->last_login_at ? $user->last_login_at->format('Y/m/d H:i') : '—' }}
                    </span>
                </td>
                <td class="px-4 py-3 text-left">
                    <div class="flex items-center gap-1 justify-end">
                        <a href="{{ route('admin.users.show', $user) }}"
                           class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:text-brandBlue hover:bg-blue-50 transition-all" title="مشاهده">
                            <i class="fa-solid fa-eye text-xs"></i>
                        </a>
                        @can('block_users')
                        @if($user->id !== auth()->id())
                        {{-- data-confirm is intercepted by adminConfirm() in
                             admin/partials/scripts.blade.php, replacing the
                             Store's unstyled native confirm(). --}}
                        <form method="POST" action="{{ route('admin.users.toggle-block', $user) }}" class="inline"
                              data-confirm="{{ $user->status === 'active' ? 'مسدود کردن' : 'فعال کردن' }} کاربر «{{ $user->full_name }}»؟">
                            @csrf @method('PATCH')
                            <button type="submit"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 transition-all {{ $user->status === 'active' ? 'hover:text-red-600 hover:bg-red-50' : 'hover:text-green-600 hover:bg-green-50' }}"
                                    title="{{ $user->status === 'active' ? 'مسدود کردن' : 'فعال کردن' }}">
                                <i class="fa-solid {{ $user->status === 'active' ? 'fa-ban' : 'fa-circle-check' }} text-xs"></i>
                            </button>
                        </form>
                        @endif
                        @endcan
                    </div>
                </td>
            </tr>
            @endforeach
        </x-admin.table>
    @endif
</x-admin.panel>

@endsection
