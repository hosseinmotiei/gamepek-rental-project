@extends('admin.layouts.app')

@section('title', $product->title_fa)
@section('page_badge', 'جزئیات محصول')
@section('breadcrumb')
    <a href="{{ route('admin.products.index') }}" class="hover:text-brandBlue transition-colors text-sm">محصولات</a>
    <i class="fa-solid fa-chevron-left text-[10px] mx-1"></i>
    <span class="text-gray-700 text-sm truncate max-w-[200px]">{{ $product->title_fa }}</span>
@endsection

@section('content')

<div class="flex flex-wrap items-center gap-3 mb-6">
    @can('edit_products')
    <a href="{{ route('admin.products.edit', $product) }}"
       class="inline-flex items-center gap-2 bg-brandBlue text-white text-sm font-bold px-4 py-2.5 rounded-xl hover:bg-blue-700 transition-colors">
        <i class="fa-solid fa-pen"></i> ویرایش
    </a>
    <form method="POST" action="{{ route('admin.products.toggle', $product) }}" class="inline">
        @csrf @method('PATCH')
        <button type="submit" class="inline-flex items-center gap-2 {{ $product->is_active ? 'bg-orange-50 text-orange-600 border border-orange-200' : 'bg-green-50 text-green-600 border border-green-200' }} text-sm font-bold px-4 py-2.5 rounded-xl hover:opacity-80 transition-opacity">
            <i class="fa-solid {{ $product->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i>
            {{ $product->is_active ? 'غیرفعال کردن' : 'فعال کردن' }}
        </button>
    </form>
    @endcan
    <a href="{{ route('products.show', $product->slug) }}" target="_blank"
       class="inline-flex items-center gap-2 text-sm text-gray-600 border border-gray-200 px-4 py-2.5 rounded-xl hover:border-brandBlue hover:text-brandBlue transition-all">
        <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i> مشاهده در سایت
    </a>
</div>

<div class="flex flex-col xl:flex-row gap-6">

    {{-- Left: main info --}}
    <div class="flex-1 flex flex-col gap-5">

        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <div class="flex gap-5">
                {{-- Image --}}
                <div class="w-36 h-36 shrink-0 bg-gray-50 rounded-xl border border-gray-100 flex items-center justify-center">
                    @if($product->main_image)
                    <img src="{{ product_image_url($product) }}" alt="{{ $product->title_fa }}"
                         class="w-full h-full object-contain mix-blend-multiply p-2">
                    @else
                    <i class="fa-solid fa-image text-gray-300 text-4xl"></i>
                    @endif
                </div>
                {{-- Title --}}
                <div class="flex-1">
                    <div class="flex flex-wrap gap-1.5 mb-2">
                        @if($product->is_active)
                            <span class="text-[10px] font-medium bg-green-50 text-green-600 px-2 py-0.5 rounded-full">فعال</span>
                        @else
                            <span class="text-[10px] font-medium bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">غیرفعال</span>
                        @endif
                        @if($product->is_featured)    <span class="text-[10px] bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full font-medium">ویژه</span> @endif
                        @if($product->is_best_seller) <span class="text-[10px] bg-green-50 text-green-600 px-2 py-0.5 rounded-full font-medium">پرفروش</span> @endif
                        @if($product->is_flash_sale)  <span class="text-[10px] bg-red-50 text-red-600 px-2 py-0.5 rounded-full font-medium">فلش سیل</span> @endif
                    </div>
                    <h1 class="text-lg font-bold text-gray-800 mb-0.5">{{ $product->title_fa }}</h1>
                    @if($product->title_en) <p class="text-sm text-gray-400 mb-2" dir="ltr">{{ $product->title_en }}</p> @endif
                    <p class="text-xs text-gray-500">{{ $product->category?->name_fa }}</p>
                </div>
            </div>

            @if($product->short_description)
            <div class="mt-4 pt-4 border-t border-gray-100">
                <p class="text-sm text-gray-600 leading-relaxed">{{ $product->short_description }}</p>
            </div>
            @endif
        </div>

        {{-- Gallery --}}
        @if(!empty($product->gallery_images))
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-3">گالری تصاویر</h3>
            <div class="flex flex-wrap gap-2">
                @foreach($product->gallery_images as $img)
                <img src="{{ product_image_url(null, $img) }}" alt="" class="w-20 h-20 object-contain rounded-lg border border-gray-100 mix-blend-multiply">
                @endforeach
            </div>
        </div>
        @endif

        {{-- Description --}}
        @if($product->description)
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-3">توضیحات</h3>
            <div class="text-sm text-gray-600 leading-relaxed prose max-w-none">{{ $product->description }}</div>
        </div>
        @endif

        {{-- Attributes --}}
        @if($product->attributes)
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-3">ویژگی‌ها</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                @foreach($product->attributes as $key => $val)
                <div class="bg-gray-50 rounded-xl p-3">
                    <p class="text-[10px] text-gray-400 mb-0.5">{{ $key }}</p>
                    <p class="text-sm font-medium text-gray-800">{{ $val }}</p>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- Right sidebar --}}
    <div class="w-full xl:w-72 shrink-0 flex flex-col gap-5">

        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-4">قیمت و موجودی</h3>
            <div class="flex flex-col gap-3">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">قیمت</span>
                    <span class="font-bold">{{ number_format($product->price) }} <small class="text-gray-400 font-normal">تومان</small></span>
                </div>
                @if($product->sale_price)
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">قیمت تخفیف‌دار</span>
                    <span class="font-bold text-flashRed">{{ number_format($product->sale_price) }} <small class="text-gray-400 font-normal">تومان</small></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">درصد تخفیف</span>
                    <span class="font-bold text-flashRed">{{ $product->discount_percent }}٪</span>
                </div>
                @endif
                <div class="h-px bg-gray-100"></div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">موجودی</span>
                    <span class="font-bold {{ $product->stock_quantity === 0 ? 'text-red-500' : 'text-gray-800' }}">{{ $product->stock_quantity }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">وضعیت</span>
                    @php $sLabels = ['in_stock' => 'موجود', 'out_of_stock' => 'ناموجود', 'coming_soon' => 'به زودی', 'preorder' => 'پیش‌خرید']; @endphp
                    <span>{{ $sLabels[$product->stock_status] ?? $product->stock_status }}</span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-4">اطلاعات محصول</h3>
            <div class="flex flex-col gap-2 text-sm">
                @foreach([
                    ['SKU', $product->sku, 'ltr'],
                    ['برند', $product->brand, ''],
                    ['مدل', $product->model, 'ltr'],
                    ['رنگ', $product->color, ''],
                ] as [$label, $val, $dir])
                @if($val)
                <div class="flex justify-between">
                    <span class="text-gray-500">{{ $label }}</span>
                    <span class="font-medium {{ $dir === 'ltr' ? 'font-mono text-xs' : '' }}" @if($dir) dir="{{ $dir }}" @endif>{{ $val }}</span>
                </div>
                @endif
                @endforeach
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-bold text-gray-800 text-sm mb-4">آمار</h3>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-xl p-3 text-center">
                    <p class="text-xl font-black text-brandBlue">{{ number_format($product->views_count) }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">بازدید</p>
                </div>
                <div class="bg-green-50 rounded-xl p-3 text-center">
                    <p class="text-xl font-black text-green-600">{{ number_format($product->sales_count) }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">فروش</p>
                </div>
                <div class="bg-red-50 rounded-xl p-3 text-center">
                    <p class="text-xl font-black text-red-500">{{ number_format($orderCount) }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">در سفارش‌ها</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
