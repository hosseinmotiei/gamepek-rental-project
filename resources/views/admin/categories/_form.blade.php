{{-- Shared category form. Variables: $category (existing or null), $parents, $formAction --}}
@php $editing = isset($category) && $category->exists; @endphp

<form method="POST" action="{{ $formAction }}" enctype="multipart/form-data"
      onsubmit="return submitFormWithProgress(this, {progressWrap:'category-upload-progress', progressBar:'category-upload-bar', progressText:'category-upload-pct'})">
    @csrf
    @if($editing) @method('PUT') @endif

    <div class="flex flex-col lg:flex-row gap-6">

        {{-- Main --}}
        <div class="flex-1 flex flex-col gap-5">

            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-circle-info text-brandBlue text-xs"></i> اطلاعات دسته‌بندی
                </h2>
                <div class="flex flex-col gap-4">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">نام فارسی <span class="text-red-500">*</span></label>
                            <input type="text" name="name_fa" value="{{ old('name_fa', $category->name_fa ?? '') }}"
                                   class="w-full bg-gray-50 border {{ $errors->has('name_fa') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all"
                                   placeholder="مثال: کنسول PlayStation 5" oninput="autoCatSlug(this.value)">
                            @error('name_fa') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">نام انگلیسی</label>
                            <input type="text" name="name_en" value="{{ old('name_en', $category->name_en ?? '') }}"
                                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all"
                                   placeholder="PS5 Consoles" dir="ltr">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">اسلاگ (Slug) <span class="text-red-500">*</span></label>
                            <input type="text" name="slug" id="cat-slug-field" value="{{ old('slug', $category->slug ?? '') }}"
                                   class="w-full bg-gray-50 border {{ $errors->has('slug') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm font-mono outline-none focus:border-brandBlue focus:bg-white transition-all"
                                   placeholder="ps5-consoles" dir="ltr">
                            @error('slug') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">آیکون (Font Awesome)</label>
                            <input type="text" name="icon" value="{{ old('icon', $category->icon ?? '') }}"
                                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all"
                                   placeholder="fa-gamepad" dir="ltr">
                            <p class="text-[10px] text-gray-400 mt-1">نام آیکون بدون «fa-solid»، مثلاً «fa-gamepad»</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">توضیح</label>
                        <textarea name="description" rows="3"
                                  class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all resize-none"
                                  placeholder="توضیح این دسته‌بندی...">{{ old('description', $category->description ?? '') }}</textarea>
                    </div>
                </div>
            </div>

            {{-- Image --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-image text-brandBlue text-xs"></i> تصویر دسته‌بندی
                </h2>
                <div id="category-image-preview-wrap" class="flex items-start gap-4 mb-3 p-3 bg-gray-50 rounded-xl border border-gray-200 {{ ($editing && $category->image) ? '' : 'hidden' }}">
                    <img id="category-image-preview" src="{{ ($editing && $category->image) ? media_url($category->image) : '' }}" alt="{{ $category->name_fa ?? '' }}"
                         class="w-20 h-20 object-cover rounded-lg">
                    <div class="flex flex-col justify-center gap-2">
                        <p class="text-xs text-gray-500">تصویر فعلی</p>
                        @if($editing)
                        <label class="flex items-center gap-1.5 text-xs text-red-500 cursor-pointer">
                            <input type="checkbox" name="remove_image" value="1" class="accent-red-500">
                            <span>حذف تصویر</span>
                        </label>
                        @endif
                    </div>
                </div>
                <input type="file" name="image" accept="image/*" onchange="adminPreviewImage(this, 'category-image-preview'); document.getElementById('category-image-preview-wrap').classList.remove('hidden');"
                       class="w-full text-sm text-gray-600 file:ml-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brandBlue/10 file:text-brandBlue hover:file:bg-brandBlue/20 transition-all">
                @error('image') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                <div id="category-upload-progress" class="hidden mt-3">
                    <div class="flex items-center justify-between text-[10px] text-gray-500 mb-1">
                        <span>در حال آپلود...</span>
                        <span id="category-upload-pct">0%</span>
                    </div>
                    <div class="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div id="category-upload-bar" class="h-full bg-brandBlue transition-all duration-150" style="width:0%"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="w-full lg:w-72 shrink-0 flex flex-col gap-5">

            {{-- Save --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">ذخیره</h2>

                <label class="flex items-center justify-between mb-3 cursor-pointer">
                    <span class="text-sm text-gray-700">فعال</span>
                    <div class="relative">
                        <input type="checkbox" name="is_active" value="1" class="sr-only peer"
                               {{ old('is_active', $category->is_active ?? true) ? 'checked' : '' }}>
                        <div class="w-10 h-6 bg-gray-200 rounded-full peer peer-checked:bg-brandBlue transition-colors"></div>
                        <div class="absolute top-1 right-1 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:-translate-x-4"></div>
                    </div>
                </label>

                <label class="flex items-center justify-between mb-4 cursor-pointer">
                    <span class="text-sm text-gray-700">نمایش در منو</span>
                    <div class="relative">
                        <input type="checkbox" name="show_in_menu" value="1" class="sr-only peer"
                               {{ old('show_in_menu', $category->show_in_menu ?? true) ? 'checked' : '' }}>
                        <div class="w-10 h-6 bg-gray-200 rounded-full peer peer-checked:bg-brandBlue transition-colors"></div>
                        <div class="absolute top-1 right-1 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:-translate-x-4"></div>
                    </div>
                </label>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-brandBlue text-white text-sm font-bold py-2.5 rounded-xl hover:bg-blue-700 transition-colors">
                        {{ $editing ? 'ذخیره تغییرات' : 'ایجاد دسته' }}
                    </button>
                    <a href="{{ route('admin.categories.index') }}" class="px-3 py-2.5 rounded-xl border border-gray-200 text-gray-600 text-sm hover:bg-gray-50 transition-colors">
                        <i class="fa-solid fa-times"></i>
                    </a>
                </div>
            </div>

            {{-- Hierarchy & order --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">ساختار</h2>
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">دسته والد</label>
                        <select name="parent_id" class="w-full bg-gray-50 border {{ $errors->has('parent_id') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-3 py-2.5 text-sm outline-none focus:border-brandBlue">
                            <option value="">— بدون والد (دسته اصلی) —</option>
                            @foreach($parents as $parent)
                            <option value="{{ $parent->id }}" {{ old('parent_id', $category->parent_id ?? '') == $parent->id ? 'selected' : '' }}>
                                {{ $parent->name_fa }}
                            </option>
                            @endforeach
                        </select>
                        @error('parent_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">ترتیب نمایش</label>
                        <input type="number" name="sort_order" value="{{ old('sort_order', $category->sort_order ?? 0) }}" min="0"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all" dir="ltr">
                        <p class="text-[10px] text-gray-400 mt-1">عدد کوچکتر = اول نمایش</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
function autoCatSlug(value) {
    const field = document.getElementById('cat-slug-field');
    if (!field || field.dataset.locked === '1') return;
    const slug = value.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '').replace(/-+/g, '-').replace(/^-|-$/g, '');
    field.value = slug;
}
document.getElementById('cat-slug-field')?.addEventListener('input', function() { this.dataset.locked = '1'; });
</script>
@endpush
