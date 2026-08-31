@extends('admin.layouts.app')

@section('title', 'دسته‌بندی‌های سریع')
@section('page_badge', 'دسته‌بندی‌های سریع')
@section('breadcrumb')
    <span class="text-gray-800 font-medium">دسته‌بندی‌های سریع</span>
@endsection

@section('content')
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-black text-gray-800">دسته‌بندی‌های سریع</h1>
        <p class="text-sm text-gray-500 mt-0.5">آیکون‌های سریع صفحه اصلی</p>
    </div>
    <a href="{{ route('admin.quick-categories.create') }}" class="bg-brandBlue text-white font-bold px-5 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> آیتم جدید
    </a>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">آیکون / تصویر</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">عنوان</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">لینک</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">ترتیب</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">وضعیت</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">عملیات</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @forelse($quickCategories as $qc)
            <tr class="hover:bg-gray-50 transition-colors {{ $qc->is_active ? '' : 'opacity-60' }}">
                <td class="px-4 py-3">
                    @if($qc->image)
                    <img src="{{ media_url($qc->image) }}" class="w-10 h-10 rounded-lg object-cover">
                    @elseif($qc->icon)
                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center {{ $qc->color_class ?? 'text-brandBlue' }}">
                        <i class="{{ $qc->icon }}"></i>
                    </div>
                    @else
                    <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center text-gray-400"><i class="fa-solid fa-circle-question"></i></div>
                    @endif
                </td>
                <td class="px-4 py-3">
                    <p class="font-bold text-gray-800">{{ $qc->title }}</p>
                    @if($qc->subtitle)<p class="text-xs text-gray-400">{{ $qc->subtitle }}</p>@endif
                    @if($qc->highlight)<span class="text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full">هایلایت</span>@endif
                </td>
                <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500 max-w-[200px] truncate">{{ $qc->link ?? '—' }}</td>
                <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ $qc->sort_order }}</td>
                <td class="px-4 py-3">
                    <form method="POST" action="{{ route('admin.quick-categories.toggle', $qc) }}" class="inline">
                        @csrf @method('PATCH')
                        <button type="submit" class="text-xs font-bold px-3 py-1.5 rounded-full transition-colors {{ $qc->is_active ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}">
                            {{ $qc->is_active ? 'فعال' : 'غیرفعال' }}
                        </button>
                    </form>
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.quick-categories.edit', $qc) }}" class="text-xs bg-blue-50 text-brandBlue hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors font-medium">ویرایش</a>
                        <form method="POST" action="{{ route('admin.quick-categories.destroy', $qc) }}" onsubmit="return confirm('حذف شود؟')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs bg-red-50 text-red-600 hover:bg-red-100 px-3 py-1.5 rounded-lg transition-colors font-medium">حذف</button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center py-16 text-gray-400">
                <i class="fa-solid fa-th-large text-4xl mb-3 block"></i>هیچ آیتمی یافت نشد
            </td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
