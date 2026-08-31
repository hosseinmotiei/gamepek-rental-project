@extends('admin.layouts.app')

@section('title', 'ویرایش: ' . $product->title_fa)
@section('page_badge', 'ویرایش محصول')
@section('breadcrumb')
    <a href="{{ route('admin.products.index') }}" class="hover:text-brandBlue transition-colors text-sm">محصولات</a>
    <i class="fa-solid fa-chevron-left text-[10px] mx-1"></i>
    <span class="text-gray-700 text-sm truncate max-w-[200px]">{{ $product->title_fa }}</span>
@endsection

@section('content')

@if($errors->any())
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl">
    <p class="font-bold mb-1"><i class="fa-solid fa-circle-exclamation ml-1"></i>خطاهای فرم:</p>
    <ul class="list-disc list-inside text-xs space-y-0.5">
        @foreach($errors->all() as $error) <li>{{ $error }}</li> @endforeach
    </ul>
</div>
@endif

<div class="flex items-center gap-3 mb-5">
    <a href="{{ route('admin.products.show', $product) }}"
       class="inline-flex items-center gap-1.5 text-xs text-gray-500 border border-gray-200 px-3 py-1.5 rounded-lg hover:border-brandBlue hover:text-brandBlue transition-all">
        <i class="fa-solid fa-eye text-[10px]"></i> مشاهده
    </a>
    <a href="{{ route('products.show', $product->slug) }}" target="_blank"
       class="inline-flex items-center gap-1.5 text-xs text-gray-500 border border-gray-200 px-3 py-1.5 rounded-lg hover:border-brandBlue hover:text-brandBlue transition-all">
        <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i> صفحه محصول
    </a>
</div>

@include('admin.products._form', [
    'formAction' => route('admin.products.update', $product),
    'formMethod' => 'PUT',
])

@include('admin.products._options')

@endsection
