@extends('admin.layouts.app')

@section('title', 'ویرایش — ' . $menu->title)
@section('page_badge', 'ویرایش آیتم منو')
@section('breadcrumb')
    <a href="{{ route('admin.menus.index') }}" class="hover:text-brandBlue">منوها</a>
    <i class="fa-solid fa-chevron-left text-[10px]"></i>
    <span class="text-gray-800 font-medium">ویرایش</span>
@endsection

@section('content')
<div class="max-w-2xl">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
        <h1 class="text-xl font-black text-gray-800 mb-6">ویرایش — {{ $menu->title }}</h1>

        <form method="POST" action="{{ route('admin.menus.update', $menu) }}" class="space-y-5">
            @csrf @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">عنوان <span class="text-red-500">*</span></label>
                    <input type="text" name="title" value="{{ old('title', $menu->title) }}" required class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">نوع لینک <span class="text-red-500">*</span></label>
                    <select name="type" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                        @foreach($types as $val => $label)
                        <option value="{{ $val }}" {{ old('type', $menu->type) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">موقعیت <span class="text-red-500">*</span></label>
                    <select name="location" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                        @foreach($locations as $val => $label)
                        <option value="{{ $val }}" {{ old('location', $menu->location) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">لینک URL</label>
                    <input type="text" name="url" value="{{ old('url', $menu->url) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">نام مسیر (route)</label>
                    <input type="text" name="route_name" value="{{ old('route_name', $menu->route_name) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">آیکون (FA class)</label>
                    <input type="text" name="icon" value="{{ old('icon', $menu->icon) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">منوی والد</label>
                    <select name="parent_id" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                        <option value="">— بدون والد —</option>
                        @foreach($parents as $row)
                        <option value="{{ $row['item']->id }}" {{ old('parent_id', $menu->parent_id) == $row['item']->id ? 'selected' : '' }}>
                            {{ str_repeat('— ', $row['depth']) }}{{ $row['item']->title }} ({{ $row['item']->location }})
                        </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">دسته‌بندی مرتبط</label>
                    <select name="category_id" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                        <option value="">— بدون دسته‌بندی —</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $menu->category_id) == $cat->id ? 'selected' : '' }}>{{ $cat->name_fa }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">ترتیب نمایش</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $menu->sort_order) }}" min="0" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>
            </div>

            <div class="flex gap-4 pt-2">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $menu->is_active) ? 'checked' : '' }} class="w-4 h-4 text-brandBlue rounded">
                    <span class="text-sm text-gray-700">فعال</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="opens_in_new_tab" value="0">
                    <input type="checkbox" name="opens_in_new_tab" value="1" {{ old('opens_in_new_tab', $menu->opens_in_new_tab) ? 'checked' : '' }} class="w-4 h-4 text-brandBlue rounded">
                    <span class="text-sm text-gray-700">باز شدن در تب جدید</span>
                </label>
            </div>

            <div class="flex gap-3 pt-4 border-t border-gray-100">
                <button type="submit" class="bg-brandBlue text-white font-bold px-6 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm">ذخیره تغییرات</button>
                <a href="{{ route('admin.menus.index') }}" class="bg-gray-100 text-gray-700 font-medium px-6 py-2.5 rounded-xl hover:bg-gray-200 transition-colors text-sm">انصراف</a>
            </div>
        </form>

        @php $childCount = $menu->children()->count(); @endphp
        <form method="POST" action="{{ route('admin.menus.destroy', $menu) }}" class="mt-4 pt-4 border-t border-gray-100"
              onsubmit="return confirm('{{ $childCount > 0 ? 'این آیتم و '.$childCount.' زیرمجموعه‌اش برای همیشه حذف شوند؟' : 'این آیتم برای همیشه حذف شود؟' }}')">
            @csrf @method('DELETE')
            <button type="submit" class="text-red-600 bg-red-50 hover:bg-red-100 font-bold px-6 py-2.5 rounded-xl transition-colors text-sm">
                <i class="fa-solid fa-trash ml-1"></i> حذف این آیتم{{ $childCount > 0 ? ' (و '.$childCount.' زیرمجموعه)' : '' }}
            </button>
        </form>
    </div>
</div>
@endsection
