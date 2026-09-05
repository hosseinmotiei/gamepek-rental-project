<?php

namespace App\Services\Rental;

use App\Enums\ContractState;
use App\Enums\GuaranteeState;
use App\Enums\IdentityState;
use App\Enums\RentalApplicationState;
use App\Enums\ReservationState;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The one place that moves a rental application's state.
 *
 * It DERIVES rather than commands: nextState() reads the child records and
 * answers "how far has this actually got?". advance() persists that answer.
 * Two consequences worth stating plainly, because they are the whole design:
 *
 *  1. Idempotency is structural, not defensive. Calling advance() twice with
 *     unchanged children computes the same state, sees no change, and writes
 *     nothing -- no transition row, no audit row, no event.
 *  2. No step service ever writes `rental_applications.state`. A step service
 *     changes its OWN record (verifies an identity, pays an order, signs a
 *     contract) and then calls advance(). If you find an assignment to `state`
 *     anywhere else, that is the bug.
 *
 * The only non-derived edges are approve(), reject() and cancel(), which are
 * explicit human acts and are guarded by RentalApplicationState::canTransitionTo().
 */
class RentalChainOrchestrator
{
    public function advance(RentalApplication $application, ?string $reason = null): RentalApplication
    {
        return DB::transaction(function () use ($application, $reason) {
            $locked = RentalApplication::where('id', $application->id)->lockForUpdate()->first();

            if (! $locked || $locked->state->isTerminal()) {
                return $application;
            }

            $locked->load([
                'user.identity',
                'user.bankAccounts',
                'reservation',
                'order',
                'guarantee',
                'contract',
            ]);

            $from = $locked->state;
            $to = $this->nextState($locked);

            if ($to === $from) {
                return $application;
            }

            $this->commit($locked, $from, $to, $reason);

            $application->setRawAttributes($locked->getAttributes(), true);

            return $application;
        });
    }

    /**
     * Pure predicate ladder -- no side effects, unit-testable without a
     * database. Walks from the top and returns the highest state whose facts
     * hold; because it reads only persisted child state, a re-run after a
     * crash lands on the same answer.
     */
    public function nextState(RentalApplication $application): RentalApplicationState
    {
        $identity = $application->user?->identity;
        $reservation = $application->reservation;
        $order = $application->order;
        $guarantee = $application->guarantee;
        $contract = $application->contract;

        $bankVerified = $application->user?->bankAccounts
            ?->contains(fn ($account) => $account->isVerified()) ?? false;

        $bankAny = ($application->user?->bankAccounts?->isNotEmpty()) ?? false;

        // Read top-down: the first condition that holds is the furthest the
        // application has genuinely reached.
        if ($contract?->state === ContractState::Signed) {
            // Final approval is an explicit admin act, never derived --
            // TODO(business) B8 leaves its criteria undecided, so the chain
            // stops here and waits for a person.
            return RentalApplicationState::AwaitingFinalApproval;
        }

        if ($contract?->state === ContractState::Accepted) {
            return RentalApplicationState::ContractAccepted;
        }

        if ($contract?->state === ContractState::Generated) {
            return RentalApplicationState::ContractGenerated;
        }

        if ($guarantee?->state === GuaranteeState::Verified) {
            return RentalApplicationState::GuaranteeVerified;
        }

        if ($guarantee !== null) {
            return RentalApplicationState::GuaranteePending;
        }

        if ($order?->payment_status === 'paid') {
            return RentalApplicationState::Paid;
        }

        if ($order !== null) {
            return RentalApplicationState::PaymentPending;
        }

        if ($reservation !== null && in_array($reservation->state, [
            ReservationState::Held,
            ReservationState::AwaitingPayment,
        ], true)) {
            return RentalApplicationState::ReservationHeld;
        }

        if ($bankVerified) {
            return RentalApplicationState::BankVerified;
        }

        if ($bankAny) {
            return RentalApplicationState::BankPending;
        }

        if ($identity?->state === IdentityState::Verified) {
            return RentalApplicationState::IdentityVerified;
        }

        if ($identity !== null) {
            return RentalApplicationState::IdentityPending;
        }

        return RentalApplicationState::Draft;
    }

    /** Explicit admin approval. The one gate that is never derived. */
    public function approve(RentalApplication $application, User $admin, ?string $note = null): RentalApplication
    {
        return DB::transaction(function () use ($application, $admin, $note) {
            $locked = RentalApplication::where('id', $application->id)->lockForUpdate()->first();

            if ($locked->state === RentalApplicationState::Approved) {
                return $application;
            }

            if (! $locked->state->canTransitionTo(RentalApplicationState::Approved)) {
                throw new \RuntimeException('این درخواست در وضعیت قابل تأیید نهایی نیست.');
            }

            $from = $locked->state;
            $locked->update(['approved_at' => now(), 'admin_note' => $note]);

            $this->commit($locked, $from, RentalApplicationState::Approved, $note, $admin);

            $application->setRawAttributes($locked->getAttributes(), true);

            return $application;
        });
    }

    public function reject(RentalApplication $application, User $admin, string $reason): RentalApplication
    {
        return DB::transaction(function () use ($application, $admin, $reason) {
            $locked = RentalApplication::where('id', $application->id)->lockForUpdate()->first();

            if (! $locked->state->canTransitionTo(RentalApplicationState::Rejected)) {
                throw new \RuntimeException('این درخواست در وضعیت قابل رد نیست.');
            }

            $from = $locked->state;
            $locked->update(['rejected_at' => now(), 'rejection_reason' => $reason]);

            $this->commit($locked, $from, RentalApplicationState::Rejected, $reason, $admin);

            $application->setRawAttributes($locked->getAttributes(), true);

            return $application;
        });
    }

    public function cancel(RentalApplication $application, ?User $actor, string $reason): RentalApplication
    {
        return DB::transaction(function () use ($application, $actor, $reason) {
            $locked = RentalApplication::where('id', $application->id)->lockForUpdate()->first();

            if (! $locked->state->canTransitionTo(RentalApplicationState::Cancelled)) {
                throw new \RuntimeException('این درخواست در وضعیت قابل لغو نیست.');
            }

            $from = $locked->state;
            $locked->update(['cancelled_at' => now()]);

            // TODO(business) B9: cancellation policy -- refund percentage by
            // how long before the start date, and whether the deposit hold is
            // released -- is undecided. Nothing is refunded here; the
            // cancellation is only recorded. No money moves without a rule.
            $this->commit($locked, $from, RentalApplicationState::Cancelled, $reason, $actor);

            $application->setRawAttributes($locked->getAttributes(), true);

            return $application;
        });
    }

    /** The single writer of `state`, its transition row and its audit row. */
    private function commit(
        RentalApplication $application,
        RentalApplicationState $from,
        RentalApplicationState $to,
        ?string $reason,
        ?User $actor = null,
    ): void {
        $correlationId = AuditLogger::correlationId();

        $application->update(['state' => $to, 'correlation_id' => $correlationId]);

        $actor ??= auth()->user();

        RentalApplicationTransition::create([
            'rental_application_id' => $application->id,
            'from_state' => $from->value,
            'to_state' => $to->value,
            'reason' => $reason,
            'actor_type' => $actor ? 'user' : 'system',
            'actor_id' => $actor?->id,
            'correlation_id' => $correlationId,
            'created_at' => now(),
        ]);

        AuditLogger::log(
            action: 'rental_application.transition',
            resourceType: 'RentalApplication',
            resourceId: $application->id,
            context: [
                'application_number' => $application->application_number,
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
            ],
            actor: $actor,
        );
    }
}
