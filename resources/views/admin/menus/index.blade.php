@extends('admin.layouts.app')

@section('title', 'منوها')
@section('page_badge', 'منوها')
@section('breadcrumb')
    <span class="text-gray-800 font-medium">منوها</span>
@endsection

@section('content')
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-black text-gray-800">منوها</h1>
        <p class="text-sm text-gray-500 mt-0.5">مدیریت آیتم‌های منوی سایت</p>
    </div>
    <a href="{{ route('admin.menus.create') }}" class="bg-brandBlue text-white font-bold px-5 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> آیتم جدید
    </a>
</div>

@foreach($locations as $locKey => $locLabel)
@php $locItems = $items->where('location', $locKey); @endphp
@if($locItems->count())
<div class="mb-6">
    <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-3">{{ $locLabel }}</h2>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium">عنوان</th>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">لینک</th>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">نوع</th>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">ترتیب</th>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium">وضعیت</th>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($locItems as $item)
                <tr class="hover:bg-gray-50 transition-colors {{ $item->is_active ? '' : 'opacity-60' }}">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            @if($item->icon)<i class="{{ $item->icon }} text-gray-400 w-4 text-center"></i>@endif
                            <span class="font-bold text-gray-800">{{ $item->title }}</span>
                        </div>
                        @if($item->children->count())
                        <div class="mr-6 mt-1 space-y-1">
                            @foreach($item->children as $child)
                            <div class="flex items-center gap-2 text-xs text-gray-500 group">
                                <i class="fa-solid fa-corner-down-left text-gray-300"></i>
                                <a href="{{ route('admin.menus.edit', $child) }}" class="hover:text-brandBlue">{{ $child->title }}</a>
                                <form method="POST" action="{{ route('admin.menus.destroy', $child) }}" onsubmit="return confirm('این آیتم{{ $child->children->count() ? ' و '.$child->children->count().' زیرمجموعه‌اش' : '' }} حذف شود؟')" class="opacity-0 group-hover:opacity-100 transition-opacity">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-red-400 hover:text-red-600" title="حذف"><i class="fa-solid fa-xmark"></i></button>
                                </form>
                            </div>
                            @if($child->children->count())
                            <div class="mr-6 space-y-1">
                                @foreach($child->children as $grandchild)
                                <div class="flex items-center gap-2 text-[11px] text-gray-400 group">
                                    <i class="fa-solid fa-corner-down-left text-gray-300"></i>
                                    <a href="{{ route('admin.menus.edit', $grandchild) }}" class="hover:text-brandBlue">{{ $grandchild->title }}</a>
                                    <form method="POST" action="{{ route('admin.menus.destroy', $grandchild) }}" onsubmit="return confirm('این آیتم حذف شود؟')" class="opacity-0 group-hover:opacity-100 transition-opacity">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-400 hover:text-red-600" title="حذف"><i class="fa-solid fa-xmark"></i></button>
                                    </form>
                                </div>
                                @endforeach
                            </div>
                            @endif
                            @endforeach
                        </div>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-400 max-w-[200px] truncate" dir="ltr">{{ $item->url ?: $item->route_name }}</td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-lg">{{ $item->type }}</span>
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ $item->sort_order }}</td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('admin.menus.toggle', $item) }}" class="inline">
                            @csrf @method('PATCH')
                            <button type="submit" class="text-xs font-bold px-3 py-1.5 rounded-full transition-colors {{ $item->is_active ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}">
                                {{ $item->is_active ? 'فعال' : 'غیرفعال' }}
                            </button>
                        </form>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('admin.menus.edit', $item) }}" class="text-xs bg-blue-50 text-brandBlue hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors font-medium">ویرایش</a>
                            <form method="POST" action="{{ route('admin.menus.destroy', $item) }}" onsubmit="return confirm('این آیتم و زیرمنوهایش حذف شود؟')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs bg-red-50 text-red-600 hover:bg-red-100 px-3 py-1.5 rounded-lg transition-colors font-medium">حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endforeach

@if($items->isEmpty())
<div class="bg-white rounded-2xl p-16 text-center text-gray-400 border border-gray-100">
    <i class="fa-solid fa-bars-staggered text-4xl mb-3 block"></i>هیچ آیتم منویی یافت نشد
</div>
@endif
@endsection
