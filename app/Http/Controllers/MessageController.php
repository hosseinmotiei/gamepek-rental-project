<?php

namespace App\Http\Controllers;

use App\Http\Requests\Messages\StoreConversationRequest;
use App\Http\Requests\Messages\StoreMessageRequest;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function index(): View
    {
        $conversations = auth()->user()->conversations()
            ->latest('last_message_at')
            ->paginate(10);

        return view('profile.messages.index', compact('conversations'));
    }

    public function create(): View
    {
        return view('profile.messages.create');
    }

    public function store(StoreConversationRequest $request): RedirectResponse
    {
        $conversation = DB::transaction(function () use ($request) {
            $conversation = auth()->user()->conversations()->create([
                'subject' => $request->subject,
                'status' => 'open',
                'last_message_at' => now(),
                'user_read_at' => now(),
            ]);

            $conversation->messages()->create([
                'sender_id' => auth()->id(),
                'is_admin' => false,
                'body' => $request->body,
            ]);

            return $conversation;
        });

        return redirect()->route('messages.show', $conversation)->with('success', 'پیام شما با موفقیت ارسال شد.');
    }

    public function show(Conversation $conversation): View
    {
        // Route-model-bound $conversation is not pre-scoped to the owner,
        // so ownership must be enforced here explicitly (same pattern as
        // OrderController::cancel/digitalCode).
        abort_if($conversation->user_id !== auth()->id(), 403);

        $conversation->load(['messages.sender']);
        $conversation->update(['user_read_at' => now()]);

        return view('profile.messages.show', compact('conversation'));
    }

    public function reply(StoreMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        abort_if($conversation->user_id !== auth()->id(), 403);

        if (! $conversation->isOpen()) {
            return back()->with('error', 'این گفتگو بسته شده و امکان ارسال پیام جدید در آن وجود ندارد.');
        }

        DB::transaction(function () use ($request, $conversation) {
            $conversation->messages()->create([
                'sender_id' => auth()->id(),
                'is_admin' => false,
                'body' => $request->body,
            ]);

            $conversation->update([
                'last_message_at' => now(),
                'user_read_at' => now(),
            ]);
        });

        return back()->with('success', 'پیام شما ارسال شد.');
    }
}
