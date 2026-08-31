@extends('admin.layouts.app')

@section('title', $conversation->subject)
@section('page_badge', 'پیام‌ها')
@section('breadcrumb')
<a href="{{ route('admin.messages.index') }}" class="text-gray-400 hover:text-brandBlue text-sm">پیام‌ها</a>
<span class="text-gray-300 mx-1">/</span>
<span class="text-gray-700 text-sm">{{ $conversation->subject }}</span>
@endsection

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-lg font-bold text-gray-800">{{ $conversation->subject }}</h1>
        @if($conversation->user)
        <p class="text-xs text-gray-500 mt-0.5">{{ $conversation->user->full_name }} — <span dir="ltr">{{ $conversation->user->mobile }}</span></p>
        @endif
    </div>
    <div class="flex items-center gap-2">
        <span class="text-xs px-3 py-1.5 rounded-full font-bold {{ $conversation->status === 'open' ? 'bg-blue-100 text-brandBlue' : 'bg-gray-100 text-gray-500' }}">
            {{ $conversation->status_label }}
        </span>
        @can('close_conversations')
        @if($conversation->isOpen())
        <form method="POST" action="{{ route('admin.messages.close', $conversation) }}">
            @csrf
            <button type="submit" class="text-xs font-bold text-red-600 border border-red-200 bg-red-50 hover:bg-red-100 px-3 py-1.5 rounded-xl transition-colors">
                <i class="fa-solid fa-lock ml-1"></i> بستن گفتگو
            </button>
        </form>
        @else
        <form method="POST" action="{{ route('admin.messages.reopen', $conversation) }}">
            @csrf
            <button type="submit" class="text-xs font-bold text-brandBlue border border-blue-200 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-xl transition-colors">
                <i class="fa-solid fa-lock-open ml-1"></i> بازگشایی گفتگو
            </button>
        </form>
        @endif
        @endcan
    </div>
</div>

@if(session('success'))
<div class="mb-4 bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl flex items-center gap-2">
    <i class="fa-solid fa-circle-check"></i> {{ session('success') }}
</div>
@endif
@if(session('error'))
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl flex items-center gap-2">
    <i class="fa-solid fa-circle-exclamation"></i> {{ session('error') }}
</div>
@endif

<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 md:p-6 flex flex-col gap-4 mb-5">
    @foreach($conversation->messages as $message)
    <div class="flex {{ $message->is_admin ? 'justify-start' : 'justify-end' }}">
        <div class="max-w-[80%] {{ $message->is_admin ? 'bg-brandBlue text-white' : 'bg-gray-100 text-gray-800' }} rounded-2xl px-4 py-3">
            <p class="text-sm leading-relaxed whitespace-pre-line">{{ $message->body }}</p>
            <div class="flex items-center gap-1.5 mt-1.5 {{ $message->is_admin ? 'text-blue-100' : 'text-gray-400' }} text-[10px]">
                <span>{{ $message->is_admin ? ($message->sender->full_name ?? 'پشتیبانی') : ($conversation->user->full_name ?? 'کاربر') }}</span>
                <span>·</span>
                <span>{{ $message->created_at->format('Y/m/d H:i') }}</span>
            </div>
        </div>
    </div>
    @endforeach
</div>

@can('reply_messages')
@if($conversation->isOpen())
<form method="POST" action="{{ route('admin.messages.reply', $conversation) }}" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 flex flex-col gap-3">
    @csrf
    <textarea name="body" rows="3" maxlength="5000" placeholder="پاسخ خود را بنویسید..." class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-800 outline-none focus:border-brandBlue resize-none"></textarea>
    <button type="submit" class="self-start bg-brandBlue text-white font-bold py-2.5 px-6 rounded-xl text-sm hover:bg-blue-600 transition-colors">ارسال پاسخ</button>
</form>
@else
<div class="bg-gray-50 border border-gray-200 text-gray-500 text-sm px-4 py-3 rounded-xl text-center">
    این گفتگو بسته شده است. برای پاسخ دادن ابتدا آن را بازگشایی کنید.
</div>
@endif
@endcan

@endsection
