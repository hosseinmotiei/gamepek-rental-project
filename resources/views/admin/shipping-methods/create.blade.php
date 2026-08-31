@extends('admin.layouts.app')

@section('title', 'روش ارسال جدید')
@section('page_badge', 'روش ارسال جدید')
@section('breadcrumb')
    <a href="{{ route('admin.shipping-methods.index') }}" class="hover:text-brandBlue">روش‌های ارسال</a>
    <i class="fa-solid fa-chevron-left text-[10px]"></i>
    <span class="text-gray-800 font-medium">جدید</span>
@endsection

@section('content')
<form method="POST" action="{{ route('admin.shipping-methods.store') }}" class="max-w-2xl bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-5">
@csrf

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">عنوان فارسی <span class="text-red-500">*</span></label>
        <input type="text" name="title_fa" value="{{ old('title_fa') }}" required
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue @error('title_fa') border-red-400 @enderror">
        @error('title_fa')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">عنوان انگلیسی (داخلی) <span class="text-red-500">*</span></label>
        <input type="text" name="title" value="{{ old('title') }}" required dir="ltr"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue @error('title') border-red-400 @enderror">
        @error('title')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700 mb-1.5">توضیحات</label>
    <textarea name="description" rows="2"
              class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue resize-none">{{ old('description') }}</textarea>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">هزینه ارسال (تومان) <span class="text-red-500">*</span></label>
        <input type="number" name="base_cost" value="{{ old('base_cost', 0) }}" required min="0"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue @error('base_cost') border-red-400 @enderror">
        @error('base_cost')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">شهر (خالی = سراسری)</label>
        <input type="text" name="city" value="{{ old('city') }}"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-5">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">حداقل روز تحویل <span class="text-red-500">*</span></label>
        <input type="number" name="min_days" value="{{ old('min_days', 0) }}" required min="0"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue @error('min_days') border-red-400 @enderror">
        @error('min_days')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">حداکثر روز تحویل <span class="text-red-500">*</span></label>
        <input type="number" name="max_days" value="{{ old('max_days', 0) }}" required min="0"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue @error('max_days') border-red-400 @enderror">
        @error('max_days')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">ترتیب نمایش <span class="text-red-500">*</span></label>
        <input type="number" name="sort_order" value="{{ old('sort_order', 0) }}" required min="0"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700 mb-1.5">متن زمان تحویل (اختیاری — جایگزین نمایش «X تا Y روز»)</label>
    <input type="text" name="estimated_delivery_text" value="{{ old('estimated_delivery_text') }}"
           class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue"
           placeholder="مثلاً: همان روز">
</div>

<label class="flex items-center gap-3 cursor-pointer">
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="w-4 h-4 text-brandBlue rounded">
    <span class="text-sm font-medium text-gray-700">فعال (در چک‌اوت نمایش داده شود)</span>
</label>

<div class="flex gap-3 pt-2">
    <button type="submit" class="bg-brandBlue text-white font-bold px-6 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm">ذخیره</button>
    <a href="{{ route('admin.shipping-methods.index') }}" class="text-center bg-gray-100 text-gray-700 font-medium px-6 py-2.5 rounded-xl hover:bg-gray-200 transition-colors text-sm">انصراف</a>
</div>

</form>
@endsection
