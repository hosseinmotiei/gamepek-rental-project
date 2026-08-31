{{--
    Shared product form partial.
    Variables available: $product (existing or null), $categories, $formAction, $formMethod
--}}
@php $editing = isset($product) && $product->exists; @endphp

<form method="POST" action="{{ $formAction }}" enctype="multipart/form-data" id="product-form"
      onsubmit="return submitFormWithProgress(this, {progressWrap:'product-upload-progress', progressBar:'product-upload-bar', progressText:'product-upload-pct'})">
    @csrf
    @if($editing) @method('PUT') @endif

    <div class="flex flex-col xl:flex-row gap-6">

        {{-- ══ MAIN COLUMN ══ --}}
        <div class="flex-1 flex flex-col gap-5">

            {{-- Basic Info --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-circle-info text-brandBlue text-xs"></i> اطلاعات اصلی
                </h2>
                <div class="flex flex-col gap-4">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">عنوان فارسی <span class="text-red-500">*</span></label>
                            <input type="text" name="title_fa" value="{{ old('title_fa', $product->title_fa ?? '') }}"
                                   class="w-full bg-gray-50 border {{ $errors->has('title_fa') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all"
                                   placeholder="مثال: کنسول PS5 Slim" oninput="autoSlug(this.value)">
                            @error('title_fa') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">عنوان انگلیسی</label>
                            <input type="text" name="title_en" value="{{ old('title_en', $product->title_en ?? '') }}"
                                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all"
                                   placeholder="PlayStation 5 Slim" dir="ltr">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">اسلاگ (Slug) <span class="text-red-500">*</span></label>
                            <input type="text" name="slug" id="slug-field" value="{{ old('slug', $product->slug ?? '') }}"
                                   class="w-full bg-gray-50 border {{ $errors->has('slug') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm font-mono outline-none focus:border-brandBlue focus:bg-white transition-all"
                                   placeholder="ps5-slim-disc" dir="ltr">
                            @error('slug') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">SKU</label>
                            <input type="text" name="sku" value="{{ old('sku', $product->sku ?? '') }}"
                                   class="w-full bg-gray-50 border {{ $errors->has('sku') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm font-mono outline-none focus:border-brandBlue focus:bg-white transition-all"
                                   placeholder="GPK-PS5-SLIM-DISC" dir="ltr">
                            @error('sku') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">برند</label>
                            <input type="text" name="brand" value="{{ old('brand', $product->brand ?? 'Sony') }}"
                                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">مدل</label>
                            <input type="text" name="model" value="{{ old('model', $product->model ?? '') }}"
                                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all" dir="ltr">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">رنگ</label>
                            <input type="text" name="color" value="{{ old('color', $product->color ?? '') }}"
                                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">توضیح کوتاه</label>
                        <textarea name="short_description" rows="2"
                                  class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all resize-none"
                                  placeholder="توضیح کوتاه برای کارت محصول...">{{ old('short_description', $product->short_description ?? '') }}</textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">توضیحات کامل</label>
                        <textarea name="description" rows="7"
                                  class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all resize-y"
                                  placeholder="توضیحات کامل محصول...">{{ old('description', $product->description ?? '') }}</textarea>
                    </div>
                </div>
            </div>

            {{-- Images --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-images text-brandBlue text-xs"></i> تصاویر
                </h2>

                {{-- Main image --}}
                <div class="mb-5">
                    <label class="block text-xs font-medium text-gray-700 mb-2">تصویر اصلی</label>
                    @if($editing && $product->main_image)
                    <div class="flex items-start gap-4 mb-3 p-3 bg-gray-50 rounded-xl border border-gray-200">
                        <img src="{{ media_url($product->main_image) }}" alt="تصویر فعلی"
                             class="w-20 h-20 object-contain mix-blend-multiply rounded-lg">
                        <div class="flex flex-col justify-center gap-2">
                            <p class="text-xs text-gray-500">تصویر فعلی</p>
                            <label class="flex items-center gap-1.5 text-xs text-red-500 cursor-pointer">
                                <input type="checkbox" name="remove_main_image" value="1" class="accent-red-500">
                                <span>حذف تصویر فعلی</span>
                            </label>
                        </div>
                    </div>
                    @endif
                    <input type="file" name="main_image" accept="image/*" id="main-image-input"
                           onchange="adminPreviewImage(this, 'main-image-preview')"
                           class="w-full text-sm text-gray-600 file:ml-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brandBlue/10 file:text-brandBlue hover:file:bg-brandBlue/20 transition-all">
                    @error('main_image') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    <p class="text-[10px] text-gray-400 mt-1">حداکثر ۲ مگابایت، فرمت‌های: JPG, PNG, WebP</p>
                    <img id="main-image-preview" class="hidden mt-3 w-20 h-20 object-contain mix-blend-multiply rounded-lg border border-gray-200 p-1">

                    {{-- Real upload progress (XMLHttpRequest upload.progress event, not a fake timer) --}}
                    <div id="product-upload-progress" class="hidden mt-3">
                        <div class="flex items-center justify-between text-[10px] text-gray-500 mb-1">
                            <span>در حال آپلود...</span>
                            <span id="product-upload-pct">0%</span>
                        </div>
                        <div class="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div id="product-upload-bar" class="h-full bg-brandBlue transition-all duration-150" style="width:0%"></div>
                        </div>
                    </div>
                </div>

                {{-- Gallery images --}}
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-2">گالری تصاویر <span class="text-gray-400 font-normal">(می‌توانید چند فایل انتخاب کنید)</span></label>

                    @if($editing && !empty($product->gallery_images))
                    <div class="flex flex-wrap gap-2 mb-3">
                        @foreach($product->gallery_images as $img)
                        <div class="relative group">
                            <img src="{{ media_url($img) }}" alt="gallery"
                                 class="w-16 h-16 object-contain rounded-lg border border-gray-200 mix-blend-multiply">
                            <form method="POST" action="{{ route('admin.products.gallery.remove', $product) }}" class="inline">
                                @csrf @method('DELETE')
                                <input type="hidden" name="path" value="{{ $img }}">
                                <button type="submit" onclick="return confirm('حذف این تصویر؟')"
                                        class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white rounded-full text-[10px] flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                    <i class="fa-solid fa-times"></i>
                                </button>
                            </form>
                        </div>
                        @endforeach
                    </div>
                    @endif

                    <input type="file" name="gallery_images[]" accept="image/*" multiple
                           class="w-full text-sm text-gray-600 file:ml-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-gray-100 file:text-gray-600 hover:file:bg-gray-200 transition-all">
                    @error('gallery_images.*') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- SEO --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-magnifying-glass text-brandBlue text-xs"></i> سئو
                </h2>
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">عنوان متا</label>
                        <input type="text" name="meta_title" value="{{ old('meta_title', $product->meta_title ?? '') }}"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all" dir="ltr">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">توضیحات متا</label>
                        <textarea name="meta_description" rows="2"
                                  class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all resize-none">{{ old('meta_description', $product->meta_description ?? '') }}</textarea>
                    </div>
                </div>
            </div>

            {{-- Attributes JSON --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-1 flex items-center gap-2">
                    <i class="fa-solid fa-sliders text-brandBlue text-xs"></i> ویژگی‌های پویا
                </h2>
                <p class="text-[10px] text-gray-400 mb-3">مقادیر اضافی به صورت JSON {"کلید": "مقدار"}</p>
                <textarea name="attributes_json" rows="4" dir="ltr"
                          class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-xs font-mono outline-none focus:border-brandBlue focus:bg-white transition-all resize-y"
                          placeholder='{"حافظه داخلی": "1TB SSD", "رنگ": "White"}'>{{ old('attributes_json', $editing && $product->attributes ? json_encode($product->attributes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '') }}</textarea>
            </div>
        </div>

        {{-- ══ SIDEBAR COLUMN ══ --}}
        <div class="w-full xl:w-80 shrink-0 flex flex-col gap-5">

            {{-- Publish box --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">انتشار</h2>

                <label class="flex items-center justify-between mb-4 cursor-pointer">
                    <span class="text-sm text-gray-700">فعال</span>
                    <div class="relative">
                        <input type="checkbox" name="is_active" value="1" class="sr-only peer"
                               {{ old('is_active', $product->is_active ?? true) ? 'checked' : '' }}>
                        <div class="w-10 h-6 bg-gray-200 rounded-full peer peer-checked:bg-brandBlue transition-colors"></div>
                        <div class="absolute top-1 right-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:-translate-x-4 shadow"></div>
                    </div>
                </label>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-brandBlue text-white text-sm font-bold py-2.5 rounded-xl hover:bg-blue-700 transition-colors">
                        {{ $editing ? 'ذخیره تغییرات' : 'ایجاد محصول' }}
                    </button>
                    <a href="{{ route('admin.products.index') }}" class="px-3 py-2.5 rounded-xl border border-gray-200 text-gray-600 text-sm hover:bg-gray-50 transition-colors">
                        <i class="fa-solid fa-times"></i>
                    </a>
                </div>
            </div>

            {{-- Category & Type --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">دسته‌بندی و نوع</h2>
                <div class="flex flex-col gap-4">

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">دسته‌بندی <span class="text-red-500">*</span></label>
                        <select name="category_id" id="category-select" onchange="toggleDiscEditionField()" class="w-full bg-gray-50 border {{ $errors->has('category_id') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-3 py-2.5 text-sm outline-none focus:border-brandBlue">
                            <option value="">انتخاب دسته‌بندی</option>
                            @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" data-slug="{{ $cat->slug }}" {{ old('category_id', $product->category_id ?? '') == $cat->id ? 'selected' : '' }}>
                                {{ $cat->name_fa }}
                            </option>
                            @endforeach
                        </select>
                        @error('category_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div id="disc-edition-field" class="hidden">
                        <label class="flex items-center justify-between cursor-pointer group">
                            <span class="text-xs font-medium text-gray-700 group-hover:text-gray-900">نسخه دیسک‌خور PS5</span>


                </div>
            </div>

            {{-- Pricing --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">قیمت‌گذاری</h2>
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">قیمت (تومان) <span class="text-red-500">*</span></label>
                        <input type="number" name="price" value="{{ old('price', $product->price ?? '') }}" min="0"
                               class="w-full bg-gray-50 border {{ $errors->has('price') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all" dir="ltr">
                        @error('price') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">قیمت تخفیف‌دار (تومان)</label>
                        <input type="number" name="sale_price" value="{{ old('sale_price', $product->sale_price ?? '') }}" min="0"
                               class="w-full bg-gray-50 border {{ $errors->has('sale_price') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all" dir="ltr">
                        @error('sale_price') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        <p class="text-[10px] text-gray-400 mt-1">خالی بگذارید تا تخفیفی اعمال نشود.</p>
                    </div>
                </div>
            </div>

            {{-- Inventory --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">موجودی</h2>
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">تعداد موجودی <span class="text-red-500">*</span></label>
                        <input type="number" name="stock_quantity" value="{{ old('stock_quantity', $product->stock_quantity ?? 0) }}" min="0"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all" dir="ltr">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">وضعیت موجودی <span class="text-red-500">*</span></label>
                        <select name="stock_status" class="w-full bg-gray-50 border {{ $errors->has('stock_status') ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-3 py-2.5 text-sm outline-none focus:border-brandBlue">
                            <option value="in_stock"     {{ old('stock_status', $product->stock_status ?? 'in_stock') === 'in_stock'     ? 'selected' : '' }}>موجود</option>
                            <option value="out_of_stock" {{ old('stock_status', $product->stock_status ?? '')          === 'out_of_stock' ? 'selected' : '' }}>ناموجود</option>
                            <option value="coming_soon"  {{ old('stock_status', $product->stock_status ?? '')          === 'coming_soon'  ? 'selected' : '' }}>به زودی</option>
                            <option value="preorder"     {{ old('stock_status', $product->stock_status ?? '')          === 'preorder'     ? 'selected' : '' }}>پیش‌خرید</option>
                        </select>
                    </div>
                </div>
            </div>

            {{-- Flags --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">برچسب‌های ویژه</h2>
                <div class="flex flex-col gap-3">
                    @foreach([
                        ['is_featured',    'ویژه (Featured)',        'blue'],
                        ['is_best_seller', 'پرفروش (Best Seller)',   'green'],
                        ['is_flash_sale',  'فلش سیل (Flash Sale)',   'red'],
                    ] as [$field, $label, $color])
                    <label class="flex items-center justify-between cursor-pointer group">
                        <span class="text-sm text-gray-700 group-hover:text-gray-900">{{ $label }}</span>
                        <div class="relative">
                            <input type="checkbox" name="{{ $field }}" value="1" class="sr-only peer"
                                   {{ old($field, $product->{$field} ?? false) ? 'checked' : '' }}>
                            <div class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:bg-{{ $color }}-500 transition-colors"></div>
                            <div class="absolute top-0.5 right-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:-translate-x-4"></div>
                        </div>
                    </label>
                    @endforeach
                </div>
            </div>

        </div>
    </div>
</form>

@push('scripts')
<script>
function autoSlug(value) {
    const field = document.getElementById('slug-field');
    if (!field || field.dataset.locked === '1') return;

    // Very basic slug: lowercase, replace spaces with hyphens, remove non-alphanumeric (except hyphens)
    // For Persian titles we just lowercase + replace spaces; proper transliteration would need a library.
    const slug = value.toLowerCase()
        .replace(/\s+/g, '-')
        .replace(/[^a-z0-9؀-ۿ-]/g, '')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
    field.value = slug;
}
// Lock auto-slug once user manually edits the slug field
document.getElementById('slug-field')?.addEventListener('input', function() {
    this.dataset.locked = '1';
});

// "نسخه دیسک‌خور PS5" is only relevant for products in the PS5 consoles
// category -- shown/enabled only when that category is selected, and its
// value cleared when hidden so switching category away from PS5 consoles
// doesn't silently keep saving a stale value on an unrelated product.
function toggleDiscEditionField() {
    const select = document.getElementById('category-select');
    const field = document.getElementById('disc-edition-field');
    if (!select || !field) return;

    const selectedOption = select.options[select.selectedIndex];
    const isPs5Console = selectedOption && selectedOption.dataset.slug === 'ps5-consoles';

    field.classList.toggle('hidden', !isPs5Console);
    if (!isPs5Console) {
        const checkbox = field.querySelector('input[type="checkbox"]');
        if (checkbox) checkbox.checked = false;
    }
}
document.addEventListener('DOMContentLoaded', toggleDiscEditionField);
</script>
@endpush
