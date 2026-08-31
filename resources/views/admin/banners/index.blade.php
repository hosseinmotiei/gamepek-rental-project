@extends('admin.layouts.app')

@section('title', 'بنرها')
@section('page_badge', 'بنرها')
@section('breadcrumb')
    <span class="text-gray-800 font-medium">بنرها</span>
@endsection

@section('content')
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-black text-gray-800">بنرها</h1>
        <p class="text-sm text-gray-500 mt-0.5">مدیریت بنرهای اسلایدر و تبلیغاتی</p>
    </div>
    @can('manage_banners')
    <a href="{{ route('admin.banners.create') }}" class="bg-brandBlue text-white font-bold px-5 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> بنر جدید
    </a>
    @endcan
</div>

{{-- Filters --}}
<form method="GET" action="{{ route('admin.banners.index') }}" class="bg-white rounded-2xl border border-gray-200 p-4 mb-5 shadow-sm">
    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-medium text-gray-600 mb-1.5">جستجو</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="عنوان، زیرعنوان یا برچسب..."
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="bg-brandBlue text-white text-sm font-bold px-4 py-2 rounded-xl hover:bg-blue-700 transition-colors">
                <i class="fa-solid fa-search ml-1"></i> جستجو
            </button>
            @if(request()->hasAny(['search']))
            <a href="{{ route('admin.banners.index') }}" class="text-sm text-gray-500 border border-gray-200 px-3 py-2 rounded-xl hover:bg-gray-50 transition-colors">
                <i class="fa-solid fa-times"></i>
            </a>
            @endif
        </div>
    </div>
</form>

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">بنر</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">موقعیت</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">ترتیب</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden lg:table-cell">زمان‌بندی</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">وضعیت</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">عملیات</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @forelse($banners as $banner)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        @if($banner->image)
                        <img src="{{ media_url($banner->image) }}" alt="{{ $banner->title ?: 'بنر تبلیغاتی' }}" class="w-16 h-10 object-cover rounded-lg">
                        @else
                        <div class="w-16 h-10 rounded-lg flex items-center justify-center text-white text-[10px] font-bold" style="background: linear-gradient(to right, #0066FF, #003399)">
                            {{ Str::limit($banner->title ?: 'بدون عنوان', 8) }}
                        </div>
                        @endif
                        <div>
                            <p class="font-bold text-gray-800">{{ $banner->title ?: 'بدون عنوان (فقط تصویر)' }}</p>
                            @if($banner->badge)
                            <span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">{{ $banner->badge }}</span>
                            @endif
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-lg">{{ $banner->position }}</span>
                </td>
                <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ $banner->sort_order }}</td>
                <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-500">
                    @if($banner->starts_at || $banner->ends_at)
                    <div>از: {{ $banner->starts_at?->format('Y/m/d') ?? '—' }}</div>
                    <div>تا: {{ $banner->ends_at?->format('Y/m/d') ?? '—' }}</div>
                    @else
                    <span class="text-gray-400">بدون محدودیت</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @can('manage_banners')
                    <form method="POST" action="{{ route('admin.banners.toggle', $banner) }}" class="inline">
                        @csrf @method('PATCH')
                        <button type="submit" class="text-xs font-bold px-3 py-1.5 rounded-full transition-colors {{ $banner->is_active ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}">
                            {{ $banner->is_active ? 'فعال' : 'غیرفعال' }}
                        </button>
                    </form>
                    @else
                    <span class="text-xs font-bold px-3 py-1.5 rounded-full {{ $banner->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $banner->is_active ? 'فعال' : 'غیرفعال' }}
                    </span>
                    @endcan
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        @can('manage_banners')
                        <a href="{{ route('admin.banners.edit', $banner) }}" class="text-xs bg-blue-50 text-brandBlue hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors font-medium">ویرایش</a>
                        <form method="POST" action="{{ route('admin.banners.destroy', $banner) }}" onsubmit="return confirm('آیا مطمئن هستید؟')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs bg-red-50 text-red-600 hover:bg-red-100 px-3 py-1.5 rounded-lg transition-colors font-medium">حذف</button>
                        </form>
                        @endcan
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center py-16 text-gray-400">
                <i class="fa-solid fa-image text-4xl mb-3 block"></i>
                هیچ بنری یافت نشد
            </td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="px-4 py-4 border-t border-gray-100">{{ $banners->links() }}</div>
</div>
@endsection
