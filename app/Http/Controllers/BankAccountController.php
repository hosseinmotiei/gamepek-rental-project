<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Services\Banking\BankAccountService;
use App\Services\Providers\ProviderException;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function __construct(private BankAccountService $service) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:card,iban'],
            'value' => ['required', 'string', 'max:34'],
        ], [
            'value.required' => 'وارد کردن شماره کارت یا شبا الزامی است.',
        ]);

        try {
            $account = $this->service->add($request->user(), $data['type'], $data['value']);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($request, $e->getMessage(), 422);
        }

        return $this->ok($request, 'حساب بانکی ثبت شد.', [
            'id' => $account->id,
            'mask' => $account->value_mask,
            'state' => $account->state->value,
        ]);
    }

    public function verify(Request $request, BankAccount $account)
    {
        // Ownership check -- a bank account is a user-specific resource.
        abort_unless($account->user_id === $request->user()->id, 403);

        $account->loadMissing('user.identity');

        try {
            $account = $this->service->verify($account);
        } catch (ProviderException $e) {
            return $this->fail($request, $e->persianMessage, 503);
        } catch (\RuntimeException $e) {
            return $this->fail($request, $e->getMessage(), 422);
        }

        return $this->ok($request, 'استعلام مالکیت انجام شد.', [
            'state' => $account->state->value,
            'owner_name' => $account->owner_name,
            'message' => $account->failure_reason,
        ]);
    }

    private function ok(Request $request, string $message, array $payload = [])
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['success' => true, 'message' => $message], $payload));
        }

        return back()->with('success', $message);
    }

    private function fail(Request $request, string $message, int $status)
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        return back()->withErrors(['bank_account' => $message]);
    }
}
