@extends('admin.layouts.app')

@section('title', 'دسته‌بندی‌ها')
@section('page_badge', 'دسته‌بندی‌ها')
@section('breadcrumb') <span class="text-gray-700 text-sm">دسته‌بندی‌ها</span> @endsection

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">مدیریت دسته‌بندی‌ها</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ $categories->total() }} دسته‌بندی</p>
    </div>
    @can('create_categories')
    <a href="{{ route('admin.categories.create') }}"
       class="inline-flex items-center gap-2 bg-brandBlue text-white text-sm font-bold px-4 py-2.5 rounded-xl hover:bg-blue-700 transition-colors shadow-sm shadow-blue-500/20">
        <i class="fa-solid fa-plus"></i> دسته جدید
    </a>
    @endcan
</div>

{{-- Filters --}}
<form method="GET" action="{{ route('admin.categories.index') }}" class="bg-white rounded-2xl border border-gray-200 p-4 mb-5 shadow-sm">
    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-medium text-gray-600 mb-1.5">جستجو</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="نام یا اسلاگ..."
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="bg-brandBlue text-white text-sm font-bold px-4 py-2 rounded-xl hover:bg-blue-700 transition-colors">
                <i class="fa-solid fa-search ml-1"></i> جستجو
            </button>
            @if(request()->hasAny(['search']))
            <a href="{{ route('admin.categories.index') }}" class="text-sm text-gray-500 border border-gray-200 px-3 py-2 rounded-xl hover:bg-gray-50 transition-colors">
                <i class="fa-solid fa-times"></i>
            </a>
            @endif
        </div>
    </div>
</form>

<div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
    @if($categories->isEmpty())
    <div class="py-16 text-center text-gray-400">
        <i class="fa-solid fa-layer-group text-4xl mb-3"></i>
        <p class="text-sm">دسته‌بندی‌ای یافت نشد.</p>
    </div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">نام</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">اسلاگ</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">والد</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">محصولات</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">ترتیب</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">وضعیت</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">منو</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs text-left">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($categories as $category)
                <tr class="hover:bg-gray-50 transition-colors {{ !$category->is_active ? 'opacity-60' : '' }}">

                    {{-- Name --}}
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            @if($category->icon)
                            <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center shrink-0">
                                <i class="fa-solid {{ $category->icon }} text-brandBlue text-xs"></i>
                            </div>
                            @endif
                            <div>
                                <p class="font-medium text-gray-800 text-sm">{{ $category->name_fa }}</p>
                                @if($category->name_en)
                                <p class="text-[10px] text-gray-400" dir="ltr">{{ $category->name_en }}</p>
                                @endif
                            </div>
                        </div>
                    </td>

                    {{-- Slug --}}
                    <td class="px-4 py-3 hidden md:table-cell">
                        <span class="text-xs font-mono text-gray-500">{{ $category->slug }}</span>
                    </td>

                    {{-- Parent --}}
                    <td class="px-4 py-3 hidden lg:table-cell">
                        <span class="text-xs text-gray-500">{{ $category->parent?->name_fa ?? '—' }}</span>
                    </td>

                    {{-- Products count --}}
                    <td class="px-4 py-3">
                        <span class="text-sm font-bold text-gray-700">{{ $category->products_count }}</span>
                    </td>

                    {{-- Sort order --}}
                    <td class="px-4 py-3 hidden md:table-cell">
                        <span class="text-xs text-gray-500">{{ $category->sort_order }}</span>
                    </td>

                    {{-- Status --}}
                    <td class="px-4 py-3">
                        @if($category->is_active)
                        <span class="inline-flex items-center gap-1 text-[10px] font-medium text-green-600 bg-green-50 px-2 py-1 rounded-full">
                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span> فعال
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1 text-[10px] font-medium text-gray-500 bg-gray-100 px-2 py-1 rounded-full">
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span> غیرفعال
                        </span>
                        @endif
                    </td>

                    {{-- Show in menu --}}
                    <td class="px-4 py-3 hidden md:table-cell">
                        @if($category->show_in_menu)
                        <span class="text-[10px] text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full font-medium">نمایش</span>
                        @else
                        <span class="text-[10px] text-gray-400">پنهان</span>
                        @endif
                    </td>

                    {{-- Actions --}}
                    <td class="px-4 py-3 text-left">
                        <div class="flex items-center gap-1 justify-end">
                            @can('edit_categories')
                            <a href="{{ route('admin.categories.edit', $category) }}"
                               class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:text-green-600 hover:bg-green-50 transition-all" title="ویرایش">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </a>
                            <form method="POST" action="{{ route('admin.categories.toggle', $category) }}" class="inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 transition-all"
                                        title="{{ $category->is_active ? 'غیرفعال کردن' : 'فعال کردن' }}">
                                    <i class="fa-solid {{ $category->is_active ? 'fa-toggle-on text-green-500' : 'fa-toggle-off' }} text-sm"></i>
                                </button>
                            </form>
                            @endcan
                            @can('delete_categories')
                            <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" class="inline"
                                  onsubmit="return confirm('حذف دسته «{{ $category->name_fa }}»؟ اگر محصول داشته باشد حذف نمی‌شود.')">
                                @csrf @method('DELETE')
                                <button type="submit" class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-all" title="حذف">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </button>
                            </form>
                            @endcan
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($categories->hasPages())
    <div class="px-4 py-3 border-t border-gray-100">
        {{ $categories->links() }}
    </div>
    @endif
    @endif
</div>

@endsection
