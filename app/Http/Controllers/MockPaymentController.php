<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Services\Payment\Gateways\MockGateway;
use Illuminate\Http\Request;

/**
 * The development gateway's "bank page".
 *
 * It exists so the mock gateway has a SERVER-SIDE place to record whether a
 * payment succeeded. Before this, the mock built a redirect straight to
 * /payment/callback?Status=OK and believed that query string -- meaning any
 * visitor could mark any pending order paid by typing a URL.
 *
 * Hard-gated to local/testing. In any other environment these routes 404.
 */
class MockPaymentController extends Controller
{
    public function show(Request $request, string $authority)
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $transaction = $this->transactionFor($request, $authority);

        return view('payment.mock', [
            'transaction' => $transaction,
            'authority' => $authority,
        ]);
    }

    public function confirm(Request $request, string $authority)
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $transaction = $this->transactionFor($request, $authority);

        $outcome = $request->input('outcome') === 'pay' ? 'paid' : 'cancelled';

        MockGateway::recordOutcome($authority, $outcome);

        return redirect()->to(route('payment.callback').'?'.http_build_query([
            'Authority' => $authority,
            'gateway' => 'mock',
        ]));
    }

    /**
     * Ownership check. A signed-in customer may only drive their own pending
     * transaction through the mock bank page -- the same rule the real gateway
     * enforces by only ever showing the page to the payer.
     */
    private function transactionFor(Request $request, string $authority): PaymentTransaction
    {
        $transaction = PaymentTransaction::where('gateway', 'mock')
            ->where('authority', $authority)
            ->firstOrFail();

        abort_unless($transaction->user_id === $request->user()?->id, 403);

        return $transaction;
    }
}
