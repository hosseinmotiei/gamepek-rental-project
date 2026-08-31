@extends('layouts.app')
@section('title', 'گیم‌پک | پیام‌های من')
@section('content')
<div class="max-w-[900px] mx-auto px-4 py-6 md:py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-xl font-black text-gray-800">پیام‌های من</h1>
        <a href="{{ route('messages.create') }}" class="inline-flex items-center gap-2 text-sm font-bold text-white bg-brandBlue hover:bg-blue-600 rounded-xl px-4 py-2.5 transition-colors">
            <i class="fa-solid fa-plus"></i> گفتگوی جدید
        </a>
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

    @if($conversations->isEmpty())
    <div class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
        <i class="fa-regular fa-comment-dots text-5xl text-gray-200 mb-4"></i>
        <p class="text-gray-500 text-sm mb-4">هنوز گفتگویی با پشتیبانی ثبت نکرده‌اید.</p>
        <a href="{{ route('messages.create') }}" class="inline-block bg-brandBlue text-white font-bold py-2.5 px-6 rounded-xl text-sm hover:bg-blue-600 transition-colors">شروع گفتگوی جدید</a>
    </div>
    @else
    <div class="flex flex-col gap-4">
        @foreach($conversations as $conversation)
        <a href="{{ route('messages.show', $conversation) }}" class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex justify-between items-center hover:border-brandBlue transition-colors">
            <div class="flex items-center gap-3">
                @if($conversation->isUnreadByUser())
                <span class="w-2.5 h-2.5 rounded-full bg-brandBlue shrink-0"></span>
                @endif
                <div>
                    <span class="font-bold text-gray-800 {{ $conversation->isUnreadByUser() ? 'font-black' : '' }}">{{ $conversation->subject }}</span>
                    <p class="text-xs text-gray-400 mt-0.5">{{ $conversation->last_message_at?->diffForHumans() }}</p>
                </div>
            </div>
            <span class="text-xs px-3 py-1 rounded-full font-bold shrink-0 {{ $conversation->status === 'open' ? 'bg-blue-100 text-brandBlue' : 'bg-gray-100 text-gray-500' }}">
                {{ $conversation->status_label }}
            </span>
        </a>
        @endforeach
    </div>
    <div class="mt-6">{{ $conversations->links() }}</div>
    @endif
</div>
@endsection
