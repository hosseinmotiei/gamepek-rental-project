@extends('admin.layouts.app')

@section('title', 'محصولات')
@section('page_badge', 'محصولات')
@section('breadcrumb') <span class="text-gray-700 text-sm">محصولات</span> @endsection

@section('content')

{{-- Header Row --}}
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">مدیریت محصولات</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ $products->total() }} محصول</p>
    </div>
    @can('create_products')
    <a href="{{ route('admin.products.create') }}"
       class="inline-flex items-center gap-2 bg-brandBlue text-white text-sm font-bold px-4 py-2.5 rounded-xl hover:bg-blue-700 transition-colors shadow-sm shadow-blue-500/20">
        <i class="fa-solid fa-plus"></i> محصول جدید
    </a>
    @endcan
</div>

{{-- Filters --}}
<form method="GET" action="{{ route('admin.products.index') }}" class="bg-white rounded-2xl border border-gray-200 p-4 mb-5 shadow-sm">
    </div>

    {{-- Flag filters --}}
</form>

{{-- Table --}}
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
    @if($products->isEmpty())
    <div class="py-16 text-center text-gray-400">
        <i class="fa-solid fa-box-open text-4xl mb-3"></i>
        <p class="text-sm">محصولی یافت نشد.</p>
    </div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">تصویر</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">محصول</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">دسته</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">قیمت</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">موجودی</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden xl:table-cell">نوع</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">برچسب‌ها</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">وضعیت</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs text-left">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($products as $product)
                <tr class="hover:bg-gray-50 transition-colors {{ !$product->is_active ? 'opacity-60' : '' }}">
                    {{-- Image --}}
                    <td class="px-4 py-3">
                        <div class="w-12 h-12 rounded-xl bg-gray-100 overflow-hidden shrink-0 flex items-center justify-center">
                            @if($product->main_image)
                            <img src="{{ product_image_url($product) }}" alt="{{ $product->title_fa }}"
                                 class="w-full h-full object-contain mix-blend-multiply">
                            @else
                            <i class="fa-solid fa-image text-gray-300 text-xl"></i>
                            @endif
                        </div>
                    </td>

                    {{-- Title --}}
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800 text-sm leading-snug max-w-[200px]">{{ $product->title_fa }}</div>
                        @if($product->title_en)
                        <div class="text-[10px] text-gray-400 mt-0.5 truncate max-w-[200px]" dir="ltr">{{ $product->title_en }}</div>
                        @endif
                        @if($product->sku)
                        <div class="text-[10px] text-gray-400 font-mono mt-0.5">{{ $product->sku }}</div>
                        @endif
                    </td>

                    {{-- Category --}}
                    <td class="px-4 py-3 hidden md:table-cell">
                        <span class="text-xs text-gray-600">{{ $product->category?->name_fa ?? '—' }}</span>
                    </td>

                    {{-- Price --}}
                    <td class="px-4 py-3 hidden lg:table-cell">
                        @if($product->sale_price)
                        <div class="text-xs text-gray-400 line-through">{{ number_format($product->price) }}</div>
                        <div class="text-sm font-bold text-flashRed">{{ number_format($product->sale_price) }}</div>
                        @else
                        <div class="text-sm font-bold text-gray-800">{{ number_format($product->price) }}</div>
                        @endif
                        <div class="text-[10px] text-gray-400">تومان</div>
                    </td>

                    {{-- Stock --}}
                    <td class="px-4 py-3 hidden lg:table-cell">
                        <div class="text-sm font-bold {{ $product->stock_quantity <= 5 && $product->stock_quantity > 0 ? 'text-orange-500' : ($product->stock_quantity === 0 ? 'text-red-500' : 'text-gray-800') }}">
                            {{ $product->stock_quantity }}
                        </div>
                        @php
                            $statusLabels = ['in_stock' => ['موجود','green'], 'out_of_stock' => ['ناموجود','red'], 'coming_soon' => ['به زودی','blue'], 'preorder' => ['پیش‌خرید','purple']];
                            [$statusLabel, $statusColor] = $statusLabels[$product->stock_status] ?? ['—', 'gray'];
                        @endphp
                        <span class="text-[10px] text-{{ $statusColor }}-600 font-medium">{{ $statusLabel }}</span>
                    </td>

                    {{-- Type --}}
                    <td class="px-4 py-3 hidden xl:table-cell">
                    </td>

                    {{-- Badges --}}
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-1">
                            @if($product->is_featured)    <span class="text-[9px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded font-medium">ویژه</span> @endif
                            @if($product->is_best_seller) <span class="text-[9px] bg-green-50 text-green-600 px-1.5 py-0.5 rounded font-medium">پرفروش</span> @endif
                            @if($product->is_flash_sale)  <span class="text-[9px] bg-red-50 text-red-600 px-1.5 py-0.5 rounded font-medium">فلش</span> @endif
                        </div>
                    </td>

                    {{-- Status --}}
                    <td class="px-4 py-3">
                        @if($product->is_active)
                        <span class="inline-flex items-center gap-1 text-[10px] font-medium text-green-600 bg-green-50 px-2 py-1 rounded-full">
                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span> فعال
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1 text-[10px] font-medium text-gray-500 bg-gray-100 px-2 py-1 rounded-full">
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span> غیرفعال
                        </span>
                        @endif
                    </td>

                    {{-- Actions --}}
                    <td class="px-4 py-3 text-left">
                        <div class="flex items-center gap-1 justify-end">
                            <a href="{{ route('admin.products.show', $product) }}" class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:text-brandBlue hover:bg-blue-50 transition-all" title="مشاهده">
                                <i class="fa-solid fa-eye text-xs"></i>
                            </a>
                            @can('edit_products')
                            <a href="{{ route('admin.products.edit', $product) }}" class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:text-green-600 hover:bg-green-50 transition-all" title="ویرایش">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </a>
                            <form method="POST" action="{{ route('admin.products.toggle', $product) }}" class="inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 transition-all"
                                        title="{{ $product->is_active ? 'غیرفعال کردن' : 'فعال کردن' }}">
                                    <i class="fa-solid {{ $product->is_active ? 'fa-toggle-on text-green-500' : 'fa-toggle-off' }} text-sm"></i>
                                </button>
                            </form>
                            @endcan
                            @can('delete_products')
                            <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="inline"
                                  onsubmit="return confirm('آیا مطمئن هستید؟ این عملیات قابل بازگشت نیست.')">
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

    {{-- Pagination --}}
    @if($products->hasPages())
    <div class="px-4 py-3 border-t border-gray-100">
        {{ $products->links() }}
    </div>
    @endif
    @endif
</div>

@endsection
