<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\View\View;

/**
 * Read-only. Displays the real, persisted Wallet backend (WalletService) --
 * no manual credit/debit control exists here, on purpose: every balance
 * change must go through WalletService inside its own locked transaction, so
 * an admin form is not a second, uncontrolled writer of `wallets.balance`.
 *
 * `view_payments` is reused rather than a new permission invented, matching
 * how this screen was already gated before it had real data behind it.
 */
class WalletController extends Controller
{
    public function index(): View
    {
        abort_if(! auth()->user()->can('view_payments'), 403);

        $wallets = Wallet::with('user')->latest('updated_at')->paginate(20);

        return view('admin.wallet.index', ['wallets' => $wallets]);
    }

    public function show(Wallet $wallet): View
    {
        abort_if(! auth()->user()->can('view_payments'), 403);

        $wallet->loadMissing('user');
        $transactions = $wallet->transactions()->latest('created_at')->paginate(30);

        return view('admin.wallet.show', ['wallet' => $wallet, 'transactions' => $transactions]);
    }
}
