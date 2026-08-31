{{-- Product variant options (ادیشن/ریجن/ظرفیت-style pill picker on the
     product page) -- only meaningful once the product exists, so this is
     included from edit.blade.php only, never from create. --}}
<div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm mt-5">
    <h2 class="font-bold text-gray-800 text-sm mb-1">گزینه‌های محصول (ادیشن، ریجن، ظرفیت و...)</h2>
    <p class="text-xs text-gray-400 mb-4">اگر این مورد چند گزینه انتخابی دارد، این‌جا گروه‌ها و گزینه‌هایشان را تعریف کنید. این گزینه‌ها به‌صورت دکمه‌های قابل‌انتخاب در صفحه مورد نمایش داده می‌شوند.</p>

    @if($product->optionGroups->isNotEmpty())
    <div class="flex flex-col gap-4 mb-5">
        @foreach($product->optionGroups as $group)
        <div class="border border-gray-100 rounded-xl p-4">
            <div class="flex items-center justify-between mb-3">
                <span class="font-bold text-gray-700 text-sm">{{ $group->title }}</span>
                <form method="POST" action="{{ route('admin.products.options.groups.destroy', [$product, $group]) }}" onsubmit="return confirm('این گروه و همه گزینه‌هاش حذف بشه؟')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-xs text-red-500 hover:text-red-700">حذف گروه</button>
                </form>
            </div>

            <div class="flex flex-wrap gap-2 mb-3">
                @foreach($group->values as $value)
                <div class="flex items-center gap-1.5 bg-gray-50 border border-gray-200 rounded-lg pl-1 pr-3 py-1.5">
                    <span class="text-xs text-gray-700">{{ $value->label }}</span>
                    @if($value->price_modifier != 0)
                    <span class="text-[9px] font-bold {{ $value->price_modifier > 0 ? 'text-green-600' : 'text-red-500' }}">
                        {{ $value->price_modifier > 0 ? '+' : '' }}{{ number_format($value->price_modifier) }} ت
                    </span>
                    @endif
                    @if($value->is_default)
                    <span class="text-[9px] bg-blue-50 text-brandBlue px-1.5 py-0.5 rounded-full">پیش‌فرض</span>
                    @endif
                    <form method="POST" action="{{ route('admin.products.options.values.destroy', [$product, $group, $value]) }}" onsubmit="return confirm('این گزینه حذف بشه؟')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-gray-400 hover:text-red-600 text-xs"><i class="fa-solid fa-xmark"></i></button>
                    </form>
                </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('admin.products.options.values.store', [$product, $group]) }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="text" name="label" required placeholder="گزینه جدید (مثلاً Ultimate Edition)"
                       class="flex-1 min-w-[160px] border border-gray-200 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:border-brandBlue">
                <input type="number" name="price_modifier" placeholder="تغییر قیمت (تومان)" dir="ltr"
                       class="w-36 border border-gray-200 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:border-brandBlue">
                <label class="flex items-center gap-1 text-[10px] text-gray-500 whitespace-nowrap">
                    <input type="checkbox" name="is_default" value="1" class="w-3.5 h-3.5">
                    پیش‌فرض
                </label>
                <button type="submit" class="text-xs bg-brandBlue text-white px-3 py-1.5 rounded-lg hover:bg-blue-700 transition-colors">افزودن</button>
            </form>
            <p class="text-[10px] text-gray-400 mt-1.5">تغییر قیمت اختیاریه — عدد مثبت یعنی این گزینه گران‌تره، عدد منفی یعنی ارزون‌تر. خالی = بدون تغییر قیمت.</p>
        </div>
        @endforeach
    </div>
    @endif

    <form method="POST" action="{{ route('admin.products.options.groups.store', $product) }}" class="flex items-center gap-2 pt-3 border-t border-gray-100">
        @csrf
        <input type="text" name="title" required placeholder="نام گروه جدید (مثلاً ریجن)"
               class="flex-1 border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
        <button type="submit" class="bg-gray-800 text-white text-sm font-bold px-4 py-2.5 rounded-xl hover:bg-gray-900 transition-colors whitespace-nowrap">+ گروه جدید</button>
    </form>
</div>
