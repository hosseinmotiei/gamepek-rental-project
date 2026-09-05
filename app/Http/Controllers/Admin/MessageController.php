<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\StoreMessageRequest;
use App\Models\Conversation;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function index(Request $request): View
    {
        abort_if(! auth()->user()->can('view_messages'), 403);

        $query = Conversation::with('user')->latest('last_message_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $conversations = $query->paginate(20)->withQueryString();

        $statusCounts = Conversation::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.messages.index', compact('conversations', 'statusCounts'));
    }

    public function show(Conversation $conversation): View
    {
        abort_if(! auth()->user()->can('view_messages'), 403);

        $conversation->load(['user', 'messages.sender']);
        $conversation->update(['admin_read_at' => now()]);

        return view('admin.messages.show', compact('conversation'));
    }

    public function reply(StoreMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        abort_if(! auth()->user()->can('reply_messages'), 403);

        if (! $conversation->isOpen()) {
            return back()->with('error', 'این گفتگو بسته شده است. برای پاسخ دادن ابتدا آن را بازگشایی کنید.');
        }

        DB::transaction(function () use ($request, $conversation) {
            $conversation->messages()->create([
                'sender_id' => auth()->id(),
                'is_admin' => true,
                'body' => $request->body,
            ]);

            $conversation->update([
                'last_message_at' => now(),
                'admin_read_at' => now(),
            ]);
        });

        ActivityLogService::log('conversation.reply', $conversation, "پاسخ به گفتگو #{$conversation->id} ({$conversation->subject})");

        return back()->with('success', 'پاسخ شما ارسال شد.');
    }

    public function close(Conversation $conversation): RedirectResponse
    {
        abort_if(! auth()->user()->can('close_conversations'), 403);

        $conversation->update(['status' => 'closed', 'closed_at' => now()]);

        ActivityLogService::log('conversation.close', $conversation, "بستن گفتگو #{$conversation->id} ({$conversation->subject})");

        return back()->with('success', 'گفتگو بسته شد.');
    }

    public function reopen(Conversation $conversation): RedirectResponse
    {
        abort_if(! auth()->user()->can('close_conversations'), 403);

        $conversation->update(['status' => 'open', 'closed_at' => null]);

        ActivityLogService::log('conversation.reopen', $conversation, "بازگشایی گفتگو #{$conversation->id} ({$conversation->subject})");

        return back()->with('success', 'گفتگو بازگشایی شد.');
    }
}
