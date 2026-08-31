@extends('admin.layouts.app')

@section('title', 'دسته جدید')
@section('page_badge', 'دسته جدید')
@section('breadcrumb')
    <a href="{{ route('admin.categories.index') }}" class="hover:text-brandBlue transition-colors text-sm">دسته‌بندی‌ها</a>
    <i class="fa-solid fa-chevron-left text-[10px] mx-1"></i>
    <span class="text-gray-700 text-sm">دسته جدید</span>
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

@include('admin.categories._form', [
    'formAction' => route('admin.categories.store'),
])

@endsection
