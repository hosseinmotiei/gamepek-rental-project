<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `wallets.balance`.
 *
 * Same single-writer discipline as RentalChainOrchestrator (state) and
 * RentalOperationService (operation state): every balance change happens
 * inside one locked transaction that ALSO writes the WalletTransaction row
 * explaining it, so a balance can never exist without the ledger entry that
 * justifies it, and vice versa.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 *  - It invents no settlement, owner payout, deposit, refund or damage-charge
 *    rule. credit()/debit() take a caller-supplied amount and reason; this
 *    class has no opinion about when either should be called, because that
 *    policy does not exist yet (see docs/business/CONFIRMED_DECISIONS.md §4
 *    and RentalOperationService's own docblock for the same discipline).
 *  - It never lets a debit push a balance negative. `wallets.balance` is
 *    additionally `unsignedBigInteger` at the schema level, so this is
 *    enforced twice.
 *  - It has no HTTP-reachable caller in this codebase yet. Nothing currently
 *    calls credit()/debit() from a controller -- this is foundation for
 *    future features (settlement, refunds, etc.), each of which will decide
 *    its own trigger and reason when it is built, not here.
 */
class WalletService
{
    /**
     * The customer's wallet, created on first use.
     *
     * `Wallet::balance` is not fillable (see the model), so creation goes
     * through direct property assignment rather than mass assignment --
     * there is no legitimate request-supplied field for a wallet at all.
     */
    public function walletFor(User $user): Wallet
    {
        $wallet = Wallet::where('user_id', $user->id)->first();

        if ($wallet) {
            return $wallet;
        }

        $wallet = new Wallet;
        $wallet->user_id = $user->id;
        $wallet->balance = 0;

        try {
            $wallet->save();
        } catch (QueryException $e) {
            if ($this->isDuplicateWallet($e)) {
                return Wallet::where('user_id', $user->id)->firstOrFail();
            }

            throw $e;
        }

        return $wallet;
    }

    public function balance(User $user): int
    {
        return $this->walletFor($user)->balance;
    }

    /**
     * Add money to a wallet.
     *
     * Idempotent when $idempotencyKey is given: a retry with the same key
     * returns the existing ledger entry untouched rather than crediting
     * twice. The unique(wallet_id, idempotency_key) index catches the
     * concurrent case a plain SELECT-then-INSERT cannot.
     */
    public function credit(
        User $user,
        int $amount,
        string $reason,
        ?string $idempotencyKey = null,
        array $context = [],
        ?User $actor = null,
    ): WalletTransaction {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $amount, $reason, $idempotencyKey, $context, $actor) {
            $wallet = $this->walletFor($user);
            $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null) {
                $existing = $this->findByIdempotencyKey($locked, $idempotencyKey);

                if ($existing) {
                    return $existing;
                }
            }

            $newBalance = $locked->balance + $amount;

            $entry = $this->writeEntry(
                $locked,
                WalletTransaction::TYPE_CREDIT,
                $amount,
                $newBalance,
                $reason,
                $idempotencyKey,
                $context,
                $actor,
            );

            if ($entry === null) {
                // A concurrent request won the idempotency race; its entry is
                // the truth, and this call adds nothing.
                return $this->findByIdempotencyKey($locked, $idempotencyKey);
            }

            $locked->balance = $newBalance;
            $locked->save();

            AuditLogger::log(
                action: 'wallet.credited',
                resourceType: 'Wallet',
                resourceId: $locked->id,
                context: [
                    'amount' => $amount,
                    'balance_after' => $newBalance,
                    'reason' => $reason,
                    'reference_number' => $entry->reference_number,
                ],
                actor: $actor,
            );

            return $entry;
        });
    }

    /**
     * Take money from a wallet.
     *
     * Refuses when the balance would go negative -- fail closed, exactly
     * like every other financial guard in this codebase (PaymentService,
     * GuaranteeService): no debit is ever partially honoured.
     *
     * @throws \RuntimeException with a Persian message when the balance is
     *                           insufficient
     */
    public function debit(
        User $user,
        int $amount,
        string $reason,
        ?string $idempotencyKey = null,
        array $context = [],
        ?User $actor = null,
    ): WalletTransaction {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $amount, $reason, $idempotencyKey, $context, $actor) {
            $wallet = $this->walletFor($user);
            $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null) {
                $existing = $this->findByIdempotencyKey($locked, $idempotencyKey);

                if ($existing) {
                    return $existing;
                }
            }

            if ($locked->balance < $amount) {
                AuditLogger::log(
                    action: 'wallet.debit_denied',
                    resourceType: 'Wallet',
                    resourceId: $locked->id,
                    result: AuditLogger::RESULT_DENIED,
                    context: ['amount' => $amount, 'balance' => $locked->balance, 'reason' => $reason],
                    actor: $actor,
                );

                throw new \RuntimeException('موجودی کیف پول کافی نیست.');
            }

            $newBalance = $locked->balance - $amount;

            $entry = $this->writeEntry(
                $locked,
                WalletTransaction::TYPE_DEBIT,
                $amount,
                $newBalance,
                $reason,
                $idempotencyKey,
                $context,
                $actor,
            );

            if ($entry === null) {
                return $this->findByIdempotencyKey($locked, $idempotencyKey);
            }

            $locked->balance = $newBalance;
            $locked->save();

            AuditLogger::log(
                action: 'wallet.debited',
                resourceType: 'Wallet',
                resourceId: $locked->id,
                context: [
                    'amount' => $amount,
                    'balance_after' => $newBalance,
                    'reason' => $reason,
                    'reference_number' => $entry->reference_number,
                ],
                actor: $actor,
            );

            return $entry;
        });
    }

    private function findByIdempotencyKey(Wallet $wallet, string $idempotencyKey): ?WalletTransaction
    {
        return WalletTransaction::where('wallet_id', $wallet->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @return WalletTransaction|null null means a concurrent request already
     *                                won the idempotency race
     */
    private function writeEntry(
        Wallet $wallet,
        string $type,
        int $amount,
        int $balanceAfter,
        string $reason,
        ?string $idempotencyKey,
        array $context,
        ?User $actor,
    ): ?WalletTransaction {
        try {
            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'reference_number' => WalletTransaction::generateReference(),
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => AuditLogger::correlationId(),
                'context' => $context,
                'actor_type' => $actor ? 'user' : 'system',
                'actor_id' => $actor?->id,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && $this->isDuplicateIdempotencyKey($e)) {
                return null;
            }

            throw $e;
        }
    }

    private function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بزرگ‌تر از صفر باشد.');
        }
    }

    private function isDuplicateWallet(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains((string) $e->getMessage(), 'wallets_user_id_unique');
    }

    private function isDuplicateIdempotencyKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains((string) $e->getMessage(), 'wallet_tx_wallet_idempotency_unique');
    }
}
