@extends('admin.layouts.app')

@section('title', 'ویرایش بخش — ' . $homeSection->title)
@section('page_badge', 'ویرایش بخش')
@section('breadcrumb')
    <a href="{{ route('admin.home-sections.index') }}" class="hover:text-brandBlue">بخش‌های صفحه اصلی</a>
    <i class="fa-solid fa-chevron-left text-[10px]"></i>
    <span class="text-gray-800 font-medium">{{ $homeSection->title }}</span>
@endsection

@section('content')
<div class="max-w-3xl">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
            <code class="text-xs bg-blue-50 text-blue-700 px-3 py-1 rounded-lg">{{ $homeSection->key }}</code>
            <h1 class="text-xl font-black text-gray-800">{{ $homeSection->title }}</h1>
        </div>

        <form method="POST" action="{{ route('admin.home-sections.update', $homeSection) }}" enctype="multipart/form-data" class="space-y-5"
              onsubmit="return submitFormWithProgress(this, {progressWrap:'hs-upload-progress', progressBar:'hs-upload-bar', progressText:'hs-upload-pct'})">
            @csrf @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">عنوان بخش</label>
                    <input type="text" name="title" value="{{ old('title', $homeSection->title) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">زیرعنوان</label>
                    <input type="text" name="subtitle" value="{{ old('subtitle', $homeSection->subtitle) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">توضیحات</label>
                    <textarea name="description" rows="3" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue resize-none">{{ old('description', $homeSection->description) }}</textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">متن دکمه CTA</label>
                    <input type="text" name="cta_text" value="{{ old('cta_text', $homeSection->cta_text) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">لینک CTA</label>
                    <input type="text" name="cta_link" value="{{ old('cta_link', $homeSection->cta_link) }}" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">حداکثر محصولات نمایش</label>
                    <input type="number" name="item_limit" value="{{ old('item_limit', $homeSection->item_limit ?? 8) }}" min="1" max="20" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">نوع انتخاب محصولات</label>
                    <select name="selection_mode" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                        @foreach(['latest' => 'جدیدترین', 'best_seller' => 'پرفروش‌ترین', 'featured' => 'ویژه', 'manual' => 'دستی'] as $val => $label)
                        <option value="{{ $val }}" {{ old('selection_mode', $homeSection->selection_mode) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">دسته‌بندی مرتبط</label>
                    <select name="category_id" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                        <option value="">— بدون دسته‌بندی —</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $homeSection->category_id) == $cat->id ? 'selected' : '' }}>{{ $cat->name_fa }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">ترتیب نمایش</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $homeSection->sort_order) }}" min="0" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">رنگ پس‌زمینه</label>
                    <input type="text" name="background_color" value="{{ old('background_color', $homeSection->background_color) }}" placeholder="#F5F5F5" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue" dir="ltr">
                </div>

                @if($homeSection->image)
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">تصویر فعلی</label>
                    <img src="{{ media_url($homeSection->image) }}" alt="{{ $homeSection->title }}" class="h-24 rounded-xl object-cover">
                </div>
                @endif

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">تصویر جدید</label>
                    <input type="file" name="image" accept="image/*" onchange="adminPreviewImage(this, 'hs-image-preview')" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                    @error('image') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    <img id="hs-image-preview" class="hidden mt-3 h-24 rounded-xl object-cover">
                    <div id="hs-upload-progress" class="hidden mt-3">
                        <div class="flex items-center justify-between text-[10px] text-gray-500 mb-1">
                            <span>در حال آپلود...</span>
                            <span id="hs-upload-pct">0%</span>
                        </div>
                        <div class="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div id="hs-upload-bar" class="h-full bg-brandBlue transition-all duration-150" style="width:0%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex gap-4 pt-2">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $homeSection->is_active) ? 'checked' : '' }} class="w-4 h-4 text-brandBlue rounded">
                    <span class="text-sm text-gray-700">فعال</span>
                </label>
            </div>

            <div class="flex gap-3 pt-4 border-t border-gray-100">
                <button type="submit" class="bg-brandBlue text-white font-bold px-6 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm">ذخیره تغییرات</button>
                <a href="{{ route('admin.home-sections.index') }}" class="bg-gray-100 text-gray-700 font-medium px-6 py-2.5 rounded-xl hover:bg-gray-200 transition-colors text-sm">انصراف</a>
            </div>
        </form>
    </div>
</div>
@endsection
