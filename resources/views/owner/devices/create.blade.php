@extends('layouts.app')

@section('title', 'گیم‌پک اجاره | ثبت دستگاه')

@section('content')
<div class="max-w-2xl mx-auto px-4 py-8">
    <h1 class="text-xl md:text-2xl font-black text-gray-800 mb-1">ثبت دستگاه جدید</h1>
    <p class="text-xs md:text-sm text-gray-500 mb-6">دستگاه پس از ثبت، توسط کارشناسان گیم‌پک بررسی می‌شود.</p>

    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('owner.devices.store') }}" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 md:p-6 space-y-5">
        @csrf

        <div>
            <label for="product_id" class="block text-sm font-medium text-gray-700 mb-1.5">مدل دستگاه</label>
            <select id="product_id" name="product_id" required
                    class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-brandBlue">
                <option value="">— انتخاب کنید —</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>{{ $product->title_fa }}</option>
                @endforeach
            </select>
            @error('product_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            @if ($products->isEmpty())
                <p class="text-xs text-amber-600 mt-1">در حال حاضر مدلی برای ثبت در دسترس نیست.</p>
            @endif
        </div>

        <div>
            <label for="serial_number" class="block text-sm font-medium text-gray-700 mb-1.5">شماره سریال</label>
            <input type="text" id="serial_number" name="serial_number" value="{{ old('serial_number') }}" required dir="ltr"
                   class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm font-mono focus:outline-none focus:border-brandBlue">
            <p class="text-[11px] text-gray-400 mt-1">شماره سریال روی بدنه دستگاه درج شده است و برای هر دستگاه یکتاست.</p>
            @error('serial_number')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="condition" class="block text-sm font-medium text-gray-700 mb-1.5">وضعیت ظاهری (اختیاری)</label>
            <input type="text" id="condition" name="condition" value="{{ old('condition') }}"
                   class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-brandBlue"
                   placeholder="مثلاً: در حد نو، بدون خط و خش">
            @error('condition')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="notes" class="block text-sm font-medium text-gray-700 mb-1.5">توضیحات (اختیاری)</label>
            <textarea id="notes" name="notes" rows="3"
                      class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-brandBlue">{{ old('notes') }}</textarea>
            @error('notes')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="flex items-center gap-3 pt-1">
            <button type="submit" class="bg-brandBlue text-white rounded-xl px-5 py-3 text-sm font-bold hover:bg-blue-700 transition-colors">ثبت دستگاه</button>
            <a href="{{ route('owner.dashboard') }}" class="text-sm text-gray-500 hover:text-gray-700">انصراف</a>
        </div>
    </form>
</div>
@endsection
