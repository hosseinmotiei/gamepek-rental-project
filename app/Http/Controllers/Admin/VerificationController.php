<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IdentityState;
use App\Http\Controllers\Controller;
use App\Models\UserIdentity;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\IdentityVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * KYC level-2 review queue.
 *
 * Identity data is the most sensitive data this app holds, so every READ of a
 * record here writes an audit row too, not just the approve/reject decisions
 * (see .claude/rules/admin-panel.md).
 */
class VerificationController extends Controller
{
    public function __construct(private IdentityVerificationService $service) {}

    public function index(Request $request): View
    {
        abort_if(! auth()->user()->can('view_verifications'), 403);

        $query = UserIdentity::with('user')->latest();

        if ($state = $request->get('state')) {
            $query->where('state', $state);
        }

        if ($search = $request->get('search')) {
            $query->whereHas('user', fn ($u) => $u
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%"));
        }

        return view('admin.verifications.index', [
            'identities' => $query->paginate(25)->withQueryString(),
            'states' => IdentityState::cases(),
        ]);
    }

    public function show(UserIdentity $verification): View
    {
        abort_if(! auth()->user()->can('view_verifications'), 403);

        $verification->load(['user', 'verifications']);

        AuditLogger::log(
            action: 'identity.read',
            resourceType: 'UserIdentity',
            resourceId: $verification->id,
            context: ['by' => 'admin', 'mask' => $verification->national_code_mask],
        );

        return view('admin.verifications.show', ['identity' => $verification]);
    }

    public function approve(Request $request, UserIdentity $verification): RedirectResponse
    {
        abort_if(! auth()->user()->can('manage_verifications'), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        $this->service->approveManually($verification, $request->user(), $note);

        return back()->with('success', 'هویت کاربر به‌صورت دستی تأیید شد.');
    }

    public function reject(Request $request, UserIdentity $verification): RedirectResponse
    {
        abort_if(! auth()->user()->can('manage_verifications'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'ثبت دلیل رد الزامی است.']);

        $this->service->reject($verification, $data['reason'], $request->user());

        return back()->with('success', 'احراز هویت رد شد.');
    }
}
