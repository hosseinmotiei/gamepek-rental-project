@extends('layouts.app')
@section('title', 'گیم‌پک | ' . $conversation->subject)
@section('content')
<div class="max-w-[800px] mx-auto px-4 py-6 md:py-8">
    <div class="flex items-center gap-3 mb-6">
        <a aria-label="بازگشت به پیام‌ها" href="{{ route('messages.index') }}" class="w-8 h-8 flex items-center justify-center text-gray-600 hover:bg-gray-100 rounded-full transition-colors">
            <i class="fa-solid fa-arrow-right"></i>
        </a>
        <div class="flex-1">
            <h1 class="text-lg font-black text-gray-800">{{ $conversation->subject }}</h1>
        </div>
        <span class="text-xs px-3 py-1 rounded-full font-bold shrink-0 {{ $conversation->status === 'open' ? 'bg-blue-100 text-brandBlue' : 'bg-gray-100 text-gray-500' }}">
            {{ $conversation->status_label }}
        </span>
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

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 md:p-6 flex flex-col gap-4 mb-5">
        @foreach($conversation->messages as $message)
        <div class="flex {{ $message->is_admin ? 'justify-end' : 'justify-start' }}">
            <div class="max-w-[80%] {{ $message->is_admin ? 'bg-gray-100 text-gray-800' : 'bg-brandBlue text-white' }} rounded-2xl px-4 py-3">
                <p class="text-sm leading-relaxed whitespace-pre-line">{{ $message->body }}</p>
                <div class="flex items-center gap-1.5 mt-1.5 {{ $message->is_admin ? 'text-gray-400' : 'text-blue-100' }} text-[10px]">
                    <span>{{ $message->is_admin ? 'پشتیبانی' : 'شما' }}</span>
                    <span>·</span>
                    <span>{{ $message->created_at->format('Y/m/d H:i') }}</span>
                    @if(!$message->is_admin)
                    <span>·</span>
                    <span>
                        @if($conversation->admin_read_at && $conversation->admin_read_at->gte($message->created_at))
                        <i class="fa-solid fa-check-double"></i> دیده شده
                        @else
                        <i class="fa-solid fa-check"></i> ارسال شده
                        @endif
                    </span>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>

    @if($conversation->isOpen())
    <form method="POST" action="{{ route('messages.reply', $conversation) }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex flex-col gap-3">
        @csrf
        <textarea aria-label="متن پاسخ" name="body" rows="3" maxlength="5000" placeholder="پاسخ خود را بنویسید..." class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-800 outline-none focus:border-brandBlue resize-none"></textarea>
        <button type="submit" class="self-start bg-brandBlue text-white font-bold py-2.5 px-6 rounded-xl text-sm hover:bg-blue-600 transition-colors">ارسال پیام</button>
    </form>
    @else
    <div class="bg-gray-50 border border-gray-200 text-gray-500 text-sm px-4 py-3 rounded-xl text-center">
        این گفتگو بسته شده و امکان ارسال پیام جدید در آن وجود ندارد.
    </div>
    @endif
</div>
@endsection
