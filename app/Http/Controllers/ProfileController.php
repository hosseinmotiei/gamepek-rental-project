<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Services\UserActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        // KYC facts live on the user_identities satellite, never on `users`
        // (that schema stays compatible with the Store). shouldBeStrict() makes
        // a lazy load here a real exception, so eager-load it.
        $user->loadMissing('identity');
        $allOrders = $user->orders()
            ->with(['items.product', 'shippingAddress'])
            ->latest()
            ->limit(50)
            ->get();
        $addresses = $user->addresses()->orderByDesc('is_default')->get();
        $orderCounts = [
            'active' => $user->orders()->whereIn('status', ['pending_payment', 'paid', 'processing', 'shipped'])->count(),
            'delivered' => $user->orders()->where('status', 'delivered')->count(),
            'returned' => $user->orders()->where('status', 'refunded')->count(),
            'cancelled' => $user->orders()->where('status', 'cancelled')->count(),
        ];
        $conversations = $user->conversations()->latest('last_message_at')->limit(10)->get();

        return view('profile.index', compact('user', 'allOrders', 'addresses', 'orderCounts', 'conversations'));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = auth()->user();
        $user->update($request->only(['full_name', 'email']));

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $user->update(['avatar' => $path]);
        }

        UserActivityLogService::log('profile.update', $user, description: 'به‌روزرسانی اطلاعات پروفایل');

        return response()->json(['success' => true, 'message' => 'پروفایل به‌روزرسانی شد.']);
    }
}
