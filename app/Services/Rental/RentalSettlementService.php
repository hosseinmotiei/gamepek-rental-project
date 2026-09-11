<?php

namespace App\Services\Rental;

use App\Enums\DeviceOwnership;
use App\Enums\RentalApplicationState;
use App\Models\Owner;
use App\Models\RentalApplication;
use App\Models\RentalReservation;
use App\Models\RentalSettlement;
use App\Models\RentalSettlementCredit;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Wallet\WalletService;
use App\Support\Rental\SettlementSplit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `rental_settlements` and `rental_settlement_credits`.
 *
 * CONFIRMED: the split applies to the rental price only (delivery fee and the
 * promissory note excluded); GamePek 35% rounded down, owner the remainder;
 * the owner's share goes to the owner's wallet, once, after the settlement
 * point (see finalize()). calculate() records the split and moves no money;
 * finalize() is the only step that credits a wallet, through WalletService.
 *
 *  - The gross basis comes from config('rental.settlement.gross_basis'); an
 *    unset or unknown value is still refused and audited
 *    (`settlement.policy_undefined`) rather than guessed.
 *  - It never nets damage, deposit, penalties or refunds against the split.
 *    None of those rules exists.
 *  - GamePek-owned devices have no owner, so no 35/65 split applies and none
 *    is recorded.
 *
 * FUTURE WALLET INTEGRATION: when a payout rule exists, it should credit the
 * owner's wallet with `owner_share` using the idempotency key
 * "settlement:{reference_number}:owner" and pass this row's correlation id, so
 * a replayed payout run can never pay twice (WalletService's unique
 * (wallet_id, idempotency_key) index already guarantees that).
 */
class RentalSettlementService
{
    /** @var list<string> The bases the code can compute. Choosing one is policy. */
    public const SUPPORTED_BASES = ['rental_total'];

    public function __construct(
        private WalletService $wallets,
        private RentalClosureReadiness $readiness,
    ) {}

    /**
     * Credit the owner's 65% to the owner's wallet -- exactly once.
     *
     * CONFIRMED settlement point: the customer's device is back, the return
     * inspection exists, the owner's two-hour window is over, and the device
     * is back with its owner. Only then. The calculation is recorded first (or
     * reused), then WalletService -- the sole wallet writer -- credits it with
     * the idempotency key "settlement:{reference}:owner", and a
     * rental_settlement_credits row records which ledger entry paid it.
     *
     * Idempotent twice over: the settlement row is locked and an existing
     * credit is returned; unique indexes on the credit row and the wallet
     * idempotency key hold even if that check were bypassed.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function finalize(RentalApplication $application, User $actor): RentalSettlementCredit
    {
        if (! $this->readiness->settlementPrerequisitesMet($application->refresh())) {
            AuditLogger::log(
                action: 'settlement.finalize_denied',
                resourceType: 'RentalApplication',
                resourceId: $application->id,
                result: AuditLogger::RESULT_DENIED,
                context: ['missing' => RentalClosureReadiness::missing($this->readiness->check($application))],
                actor: $actor,
            );

            throw new \RuntimeException('پیش‌نیازهای تسویه این اجاره هنوز کامل نشده است.');
        }

        $settlement = $this->calculate($application, $actor);

        return DB::transaction(function () use ($settlement, $actor) {
            $locked = RentalSettlement::where('id', $settlement->id)->lockForUpdate()->firstOrFail();

            $existing = RentalSettlementCredit::where('rental_settlement_id', $locked->id)->first();

            if ($existing) {
                return $existing;
            }

            if ($locked->owner_share <= 0) {
                throw new \RuntimeException('سهم مالک برای این اجاره صفر است و واریزی لازم نیست.');
            }

            $ownerUser = Owner::whereKey($locked->owner_id)->firstOrFail()->user()->firstOrFail();

            $entry = $this->wallets->credit(
                $ownerUser,
                $locked->owner_share,
                'rental_settlement',
                'settlement:'.$locked->reference_number.':owner',
                [
                    'settlement_reference' => $locked->reference_number,
                    'rental_application_id' => $locked->rental_application_id,
                    'rental_reservation_id' => $locked->rental_reservation_id,
                ],
                $actor,
            );

            $credit = new RentalSettlementCredit;
            $credit->rental_settlement_id = $locked->id;
            $credit->wallet_transaction_id = $entry->id;
            $credit->owner_user_id = $ownerUser->id;
            $credit->amount = $locked->owner_share;
            $credit->credited_by_user_id = $actor->id;
            $credit->credited_at = now();
            $credit->created_at = now();
            $credit->save();

            AuditLogger::log(
                action: 'settlement.credited',
                resourceType: 'RentalSettlement',
                resourceId: $locked->id,
                context: [
                    'reference_number' => $locked->reference_number,
                    'wallet_transaction_id' => $entry->id,
                    'owner_user_id' => $ownerUser->id,
                    'amount' => $locked->owner_share,
                ],
                actor: $actor,
            );

            return $credit;
        });
    }

    /**
     * Calculate and record the split for this application's rental.
     * Idempotent: a second call returns the first row unchanged.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function calculate(RentalApplication $application, User $actor): RentalSettlement
    {
        $basis = config('rental.settlement.gross_basis');

        // Audited before any transaction, so the denial survives the throw.
        if ($basis === null || ! in_array($basis, self::SUPPORTED_BASES, true)) {
            AuditLogger::log(
                action: 'settlement.policy_undefined',
                resourceType: 'RentalApplication',
                resourceId: $application->id,
                result: AuditLogger::RESULT_DENIED,
                context: [
                    'gross_basis' => $basis,
                    'note' => 'rental.settlement.gross_basis is undefined or unsupported (C-26/C-27 base not decided)',
                ],
                actor: $actor,
            );

            throw new \RuntimeException('مبنای محاسبه تسویه هنوز تعیین نشده است.');
        }

        return DB::transaction(function () use ($application, $actor, $basis) {
            $reservation = RentalReservation::where('rental_application_id', $application->id)
                ->lockForUpdate()
                ->first()
                ?? throw new \RuntimeException('برای این درخواست رزروی ثبت نشده است.');

            $existing = RentalSettlement::where('rental_reservation_id', $reservation->id)->first();

            if ($existing) {
                return $existing;
            }

            // Fresh read (cast to the enum): the caller's copy may be stale.
            $state = RentalApplication::whereKey($application->id)->firstOrFail(['id', 'state'])->state;

            // The rental must have finished physically: the customer's device
            // is back. This is the minimum fact a calculation needs, not a
            // settlement trigger -- nothing is paid.
            if ($state !== RentalApplicationState::Returned) {
                throw new \RuntimeException('تسویه فقط برای اجاره‌ای که دستگاه آن بازگشته است محاسبه می‌شود.');
            }

            $device = $reservation->device()->first()
                ?? throw new \RuntimeException('برای این رزرو دستگاهی ثبت نشده است.');

            if ($device->ownership !== DeviceOwnership::Owner || $device->owner_id === null) {
                throw new \RuntimeException('این دستگاه متعلق به گیم‌پک است و تقسیم سهم مالک برای آن معنا ندارد.');
            }

            $split = SettlementSplit::of($this->grossFor($reservation, $basis));

            try {
                $settlement = new RentalSettlement;
                $settlement->reference_number = RentalSettlement::generateReference();
                $settlement->rental_reservation_id = $reservation->id;
                $settlement->rental_application_id = $application->id;
                $settlement->device_id = $device->id;
                $settlement->owner_id = $device->owner_id;
                $settlement->gross_basis = $basis;
                $settlement->gross_amount = $split->gross;
                $settlement->commission_bps = $split->commissionBps;
                $settlement->gamepek_share = $split->gamepekShare;
                $settlement->owner_share = $split->ownerShare;
                $settlement->status = RentalSettlement::STATUS_CALCULATED;
                $settlement->correlation_id = AuditLogger::correlationId();
                $settlement->calculated_by_user_id = $actor->id;
                $settlement->calculated_at = now();
                $settlement->created_at = now();
                $settlement->save();
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062
                    && str_contains((string) $e->getMessage(), 'rental_reservation_id')) {
                    return RentalSettlement::where('rental_reservation_id', $reservation->id)->firstOrFail();
                }

                throw $e;
            }

            AuditLogger::log(
                action: 'settlement.calculated',
                resourceType: 'RentalSettlement',
                resourceId: $settlement->id,
                context: [
                    'reference_number' => $settlement->reference_number,
                    'rental_application_id' => $application->id,
                    'owner_id' => $settlement->owner_id,
                    'gross_basis' => $basis,
                    'gross_amount' => $split->gross,
                    'gamepek_share' => $split->gamepekShare,
                    'owner_share' => $split->ownerShare,
                ],
                actor: $actor,
            );

            return $settlement;
        });
    }

    /**
     * Read-only: the split this rental WOULD get under the configured basis,
     * or null when the basis is undecided or the device has no owner. Writes
     * nothing, so screens may show it -- clearly marked as not paid.
     */
    public function preview(RentalReservation $reservation): ?SettlementSplit
    {
        $basis = config('rental.settlement.gross_basis');

        if ($basis === null || ! in_array($basis, self::SUPPORTED_BASES, true)) {
            return null;
        }

        return SettlementSplit::of($this->grossFor($reservation, $basis));
    }

    private function grossFor(RentalReservation $reservation, string $basis): int
    {
        return match ($basis) {
            // Daily rate x days + extra-controller/game fees - discount.
            // Excludes the delivery fee and the deposit.
            'rental_total' => (int) $reservation->rental_total,
        };
    }
}
