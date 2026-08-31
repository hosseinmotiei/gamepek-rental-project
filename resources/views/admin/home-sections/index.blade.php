@extends('admin.layouts.app')

@section('title', 'بخش‌های صفحه اصلی')
@section('page_badge', 'صفحه اصلی')
@section('breadcrumb')
    <span class="text-gray-800 font-medium">بخش‌های صفحه اصلی</span>
@endsection

@section('content')
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-black text-gray-800">بخش‌های صفحه اصلی</h1>
        <p class="text-sm text-gray-500 mt-0.5">فعال/غیرفعال کردن و ویرایش تنظیمات هر بخش</p>
    </div>
    @can('manage_home_sections')
    <a href="{{ route('admin.home-sections.create') }}" class="bg-brandBlue text-white font-bold px-5 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> بخش محصولات جدید
    </a>
    @endcan
</div>

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">ترتیب</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">بخش</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">کلید</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">حد محصولات</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">وضعیت</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">عملیات</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($sections as $section)
            <tr class="hover:bg-gray-50 transition-colors {{ $section->is_active ? '' : 'opacity-60' }}">
                <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $section->sort_order }}</td>
                <td class="px-4 py-3">
                    <p class="font-bold text-gray-800">{{ $section->title }}</p>
                    @if($section->subtitle)
                    <p class="text-xs text-gray-400">{{ $section->subtitle }}</p>
                    @endif
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    <code class="text-xs bg-gray-100 px-2 py-0.5 rounded text-blue-700">{{ $section->key }}</code>
                </td>
                <td class="px-4 py-3 hidden md:table-cell text-gray-600">
                    {{ $section->item_limit ?? '—' }}
                    @if($section->selection_mode !== 'latest')
                    <span class="text-xs text-gray-400 block">{{ $section->selection_mode }}</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @can('manage_home_sections')
                    <form method="POST" action="{{ route('admin.home-sections.toggle', $section) }}" class="inline">
                        @csrf @method('PATCH')
                        <button type="submit" class="text-xs font-bold px-3 py-1.5 rounded-full transition-colors {{ $section->is_active ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}">
                            {{ $section->is_active ? 'فعال' : 'غیرفعال' }}
                        </button>
                    </form>
                    @else
                    <span class="text-xs font-bold px-3 py-1.5 rounded-full {{ $section->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ $section->is_active ? 'فعال' : 'غیرفعال' }}</span>
                    @endcan
                </td>
                <td class="px-4 py-3">
                    @can('manage_home_sections')
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.home-sections.edit', $section) }}" class="text-xs bg-blue-50 text-brandBlue hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors font-medium">ویرایش</a>
                        @if(str_starts_with($section->key, 'custom_'))
                        <form method="POST" action="{{ route('admin.home-sections.destroy', $section) }}" onsubmit="return confirm('این بخش حذف شود؟')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs bg-red-50 text-red-600 hover:bg-red-100 px-3 py-1.5 rounded-lg transition-colors font-medium">حذف</button>
                        </form>
                        @endif
                    </div>
                    @endcan
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
