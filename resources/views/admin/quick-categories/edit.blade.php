@extends('admin.layouts.app')

@section('title', 'ویرایش — ' . $quickCategory->title)
@section('page_badge', 'ویرایش آیتم')
@section('breadcrumb')
    <a href="{{ route('admin.quick-categories.index') }}" class="hover:text-brandBlue">دسته‌بندی‌های سریع</a>
    <i class="fa-solid fa-chevron-left text-[10px]"></i>
    <span class="text-gray-800 font-medium">ویرایش</span>
@endsection

@section('content')
<div class="max-w-2xl">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
        <h1 class="text-xl font-black text-gray-800 mb-6">ویرایش — {{ $quickCategory->title }}</h1>

        <form method="POST" action="{{ route('admin.quick-categories.update', $quickCategory) }}" enctype="multipart/form-data" class="space-y-5"
              onsubmit="return submitFormWithProgress(this, {progressWrap:'qc-upload-progress', progressBar:'qc-upload-bar', progressText:'qc-upload-pct'})">
            @csrf @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">عنوان <span class="text-red-500">*</span></label>
                    <input type="text" name="title" value="{{ old('title', $quickCategory->title) }}" required class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">زیرعنوان</label>
                    <input type="text" name="subtitle" value="{{ old('subtitle', $quickCategory->subtitle) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">آیکون Font Awesome</label>
                    <input type="text" name="icon" value="{{ old('icon', $quickCategory->icon) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">لینک</label>
                    <input type="text" name="link" value="{{ old('link', $quickCategory->link) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">کلاس رنگ آیکون</label>
                    <input type="text" name="color_class" value="{{ old('color_class', $quickCategory->color_class) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">کلاس پس‌زمینه</label>
                    <input type="text" name="bg_class" value="{{ old('bg_class', $quickCategory->bg_class) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">دسته‌بندی مرتبط</label>
                    <select name="category_id" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                        <option value="">— بدون دسته‌بندی —</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $quickCategory->category_id) == $cat->id ? 'selected' : '' }}>{{ $cat->name_fa }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">ترتیب نمایش</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $quickCategory->sort_order) }}" min="0" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>

                <div class="md:col-span-2">
                    @if($quickCategory->image)
                    <p class="text-sm font-medium text-gray-700 mb-2">تصویر فعلی</p>
                    <img src="{{ media_url($quickCategory->image) }}" class="w-14 h-14 rounded-xl object-cover mb-3">
                    @endif
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">تصویر جدید</label>
                    <input type="file" name="image" accept="image/*" onchange="adminPreviewImage(this, 'qc-image-preview')" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                    @error('image') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    <img id="qc-image-preview" class="hidden mt-3 w-14 h-14 rounded-xl object-cover">
                    <div id="qc-upload-progress" class="hidden mt-3">
                        <div class="flex items-center justify-between text-[10px] text-gray-500 mb-1">
                            <span>در حال آپلود...</span>
                            <span id="qc-upload-pct">0%</span>
                        </div>
                        <div class="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div id="qc-upload-bar" class="h-full bg-brandBlue transition-all duration-150" style="width:0%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex gap-4 pt-2">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $quickCategory->is_active) ? 'checked' : '' }} class="w-4 h-4 text-brandBlue rounded">
                    <span class="text-sm text-gray-700">فعال</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="highlight" value="0">
                    <input type="checkbox" name="highlight" value="1" {{ old('highlight', $quickCategory->highlight) ? 'checked' : '' }} class="w-4 h-4 text-red-500 rounded">
                    <span class="text-sm text-gray-700">هایلایت (قرمز)</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="opens_in_new_tab" value="0">
                    <input type="checkbox" name="opens_in_new_tab" value="1" {{ old('opens_in_new_tab', $quickCategory->opens_in_new_tab) ? 'checked' : '' }} class="w-4 h-4 text-brandBlue rounded">
                    <span class="text-sm text-gray-700">تب جدید</span>
                </label>
            </div>

            <div class="flex gap-3 pt-4 border-t border-gray-100">
                <button type="submit" class="bg-brandBlue text-white font-bold px-6 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm">ذخیره تغییرات</button>
                <a href="{{ route('admin.quick-categories.index') }}" class="bg-gray-100 text-gray-700 font-medium px-6 py-2.5 rounded-xl hover:bg-gray-200 transition-colors text-sm">انصراف</a>
            </div>
        </form>
    </div>
</div>
@endsection
