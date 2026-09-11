@extends('layouts.app')
@section('title', 'گیم‌پک | گفتگوی جدید')
@section('content')
<div class="max-w-[700px] mx-auto px-4 py-6 md:py-8">
    <div class="flex items-center gap-3 mb-6">
        <a aria-label="بازگشت به پیام‌ها" href="{{ route('messages.index') }}" class="w-8 h-8 flex items-center justify-center text-gray-600 hover:bg-gray-100 rounded-full transition-colors">
            <i class="fa-solid fa-arrow-right"></i>
        </a>
        <h1 class="text-xl font-black text-gray-800">شروع گفتگوی جدید</h1>
    </div>

    @if($errors->any())
    <div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl">
        <ul class="list-disc pr-5">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('messages.store') }}" class="bg-white rounded-2xl p-5 md:p-6 shadow-sm border border-gray-100 flex flex-col gap-5">
        @csrf
        <div class="flex flex-col gap-2">
            <label class="text-xs text-gray-700 font-bold">موضوع <span class="text-red-500">*</span></label>
            <input type="text" name="subject" value="{{ old('subject') }}" maxlength="150" placeholder="مثال: سوال درباره سفارش" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-800 outline-none focus:border-brandBlue">
        </div>
        <div class="flex flex-col gap-2">
            <label class="text-xs text-gray-700 font-bold">متن پیام <span class="text-red-500">*</span></label>
            <textarea name="body" rows="6" maxlength="5000" placeholder="پیام خود را بنویسید..." class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-800 outline-none focus:border-brandBlue resize-none">{{ old('body') }}</textarea>
        </div>
        <button type="submit" class="w-full bg-brandBlue text-white font-bold py-3 rounded-xl shadow-lg shadow-blue-500/20 hover:bg-blue-600 transition-colors text-sm">ارسال پیام</button>
    </form>
</div>
@endsection
