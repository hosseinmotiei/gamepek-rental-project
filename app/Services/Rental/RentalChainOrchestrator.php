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
use App\Services\Contract\Contracts\SignatureProviderInterface;
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
    public function __construct(private SignatureProviderInterface $signatures) {}

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
        // Approved is a human decision, not a derived rung. Without this an
        // ordinary advance() -- the customer merely opening their application
        // page -- would re-derive AwaitingFinalApproval from the signed
        // contract and silently revoke an admin's approval.
        if ($application->state === RentalApplicationState::Approved) {
            return RentalApplicationState::Approved;
        }

        $identity = $application->user?->identity;
        $reservation = $application->reservation;
        $order = $application->order;
        $guarantee = $application->guarantee;
        $contract = $application->contract;

        $bankVerified = $application->user?->bankAccounts
            ?->contains(fn ($account) => $account->isVerified()) ?? false;

        $bankAny = ($application->user?->bankAccounts?->isNotEmpty()) ?? false;

        $paid = $order?->payment_status === 'paid';

        // Every contract rung sits above the guarantee, so a contract row only
        // counts once the guarantee it secures is actually verified. Without
        // this a contract alone would skip both Paid and GuaranteeVerified.
        $guaranteeVerified = $paid && $guarantee?->state === GuaranteeState::Verified;

        // Read top-down: the first condition that holds is the furthest the
        // application has genuinely reached.
        if ($guaranteeVerified && $contract?->state === ContractState::Signed) {
            // Final approval is an explicit admin act, never derived --
            // TODO(business) B8 leaves its criteria undecided, so the chain
            // stops here and waits for a person.
            return RentalApplicationState::AwaitingFinalApproval;
        }

        if ($guaranteeVerified && $contract?->state === ContractState::Accepted) {
            return RentalApplicationState::ContractAccepted;
        }

        if ($guaranteeVerified && $contract?->state === ContractState::Generated) {
            return RentalApplicationState::ContractGenerated;
        }

        // Guarantee rungs sit ABOVE payment on the ladder, so they must also
        // require it. Without this an unpaid application that somehow acquired
        // a guarantee row would derive GuaranteePending and skip Paid entirely.
        if ($guaranteeVerified) {
            return RentalApplicationState::GuaranteeVerified;
        }

        if ($paid && $guarantee !== null) {
            return RentalApplicationState::GuaranteePending;
        }

        if ($paid) {
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

    /**
     * Explicit admin approval. The one gate that is never derived.
     *
     * TODO(business) B8: WHICH applications deserve approval is still the
     * owner's decision, so nothing here decides it. What is checked is only
     * that the chain is actually complete and the signed text is still the
     * text that was signed -- an admin cannot approve their way past a missing
     * signature or a tampered contract.
     */
    public function approve(RentalApplication $application, User $admin, ?string $note = null): RentalApplication
    {
        $current = RentalApplication::with('contract.signatures')->find($application->id);

        if ($current?->state === RentalApplicationState::Approved) {
            $application->setRawAttributes($current->getAttributes(), true);

            return $application;
        }

        // Checked before the transaction: a denial both audits and throws, and
        // an audit row written inside a transaction that then throws is rolled
        // back with it. The lock below re-checks what the race could change.
        $this->assertApprovable($current, $admin);

        return DB::transaction(function () use ($application, $admin, $note) {
            $locked = RentalApplication::where('id', $application->id)->lockForUpdate()->first();

            // The concurrency loser lands here: already approved, so no second
            // approval, no second transition, no second audit row.
            if ($locked->state === RentalApplicationState::Approved) {
                $application->setRawAttributes($locked->getAttributes(), true);

                return $application;
            }

            if ($locked->state !== RentalApplicationState::AwaitingFinalApproval) {
                throw new \RuntimeException('این درخواست در وضعیت قابل تأیید نهایی نیست.');
            }

            $locked->load(['contract.signatures']);

            $from = $locked->state;
            $locked->update(['approved_at' => now(), 'admin_note' => $note]);

            $this->commit($locked, $from, RentalApplicationState::Approved, $note, $admin);

            $contract = $locked->contract;

            AuditLogger::log(
                action: 'rental_application.approved',
                resourceType: 'RentalApplication',
                resourceId: $locked->id,
                context: [
                    'application_number' => $locked->application_number,
                    'contract_id' => $contract?->id,
                    'contract_number' => $contract?->number,
                    'template_key' => $contract?->template_key,
                    'template_version' => $contract?->template_version,
                    'content_hash' => $contract?->content_hash,
                    'note' => $note,
                ],
                actor: $admin,
            );

            $application->setRawAttributes($locked->getAttributes(), true);

            return $application;
        });
    }

    /**
     * Everything that must still hold at the moment of approval. Each failure
     * is audited as denied and stops the transaction, so a refused approval
     * writes no state and no approval event.
     */
    private function assertApprovable(?RentalApplication $application, User $admin): void
    {
        $contract = $application?->contract;

        $reason = match (true) {
            $application === null => 'application_missing',
            $application->state !== RentalApplicationState::AwaitingFinalApproval => 'state_not_awaiting_final_approval',
            $contract === null => 'contract_missing',
            $contract->state !== ContractState::Signed => 'contract_not_signed',
            ! $contract->isIntact() => 'contract_tampered',
            $contract->signatures->isEmpty() => 'signature_missing',
            ! $contract->signatures->contains(fn ($signature) => $this->signatures->verify($signature)) => 'signature_invalid',
            default => null,
        };

        if ($reason === null) {
            return;
        }

        AuditLogger::log(
            action: 'rental_application.approval_denied',
            resourceType: 'RentalApplication',
            resourceId: $application?->id,
            result: AuditLogger::RESULT_DENIED,
            context: ['reason' => $reason, 'state' => $application?->state->value],
            actor: $admin,
        );

        throw new \RuntimeException('این درخواست در وضعیت قابل تأیید نهایی نیست.');
    }

    /**
     * The post-approval lifecycle: Approved -> Active -> Returned -> Closed.
     *
     * TODO(business) B14. None of the three triggers is defined, so this
     * refuses every call and records why. It is the single place a trigger will
     * attach once the owner decides one; until then no route reaches it,
     * nextState() never derives these states, and an approved rental simply
     * stays approved.
     *
     * The mechanism below (lock, adjacency check, commit, audit) is real and
     * tested; the POLICY that would permit a call is absent, and is not
     * invented here.
     */
    public function transitionPostApproval(
        RentalApplication $application,
        RentalApplicationState $target,
        ?User $actor = null,
    ): RentalApplication {
        $trigger = match ($target) {
            RentalApplicationState::Active => 'activation_trigger',
            RentalApplicationState::Returned => 'return_trigger',
            RentalApplicationState::Closed => 'closure_trigger',
            default => null,
        };

        if ($trigger === null || config('rental.lifecycle.'.$trigger) === null) {
            AuditLogger::log(
                action: 'rental_application.policy_undefined',
                resourceType: 'RentalApplication',
                resourceId: $application->id,
                result: AuditLogger::RESULT_DENIED,
                context: [
                    'from' => $application->state->value,
                    'to' => $target->value,
                    'note' => 'rental.lifecycle.'.($trigger ?? 'unsupported_target').' is undefined (TODO(business) B14)',
                ],
                actor: $actor,
            );

            throw new \RuntimeException('سیاست این مرحله از چرخه اجاره هنوز تعریف نشده است.');
        }

        return DB::transaction(function () use ($application, $target, $actor) {
            $locked = RentalApplication::where('id', $application->id)->lockForUpdate()->first();

            // The retry and the concurrency loser both land here.
            if ($locked->state === $target) {
                $application->setRawAttributes($locked->getAttributes(), true);

                return $application;
            }

            if (! $locked->state->canTransitionTo($target)) {
                throw new \RuntimeException('این درخواست در وضعیت لازم برای این مرحله نیست.');
            }

            $from = $locked->state;
            $this->commit($locked, $from, $target, null, $actor);

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

        // Explicit assignment, not mass assignment: `state` is not fillable.
        $application->state = $to;
        $application->correlation_id = $correlationId;
        $application->save();

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
