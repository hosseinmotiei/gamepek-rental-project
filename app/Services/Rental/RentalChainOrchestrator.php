<?php

namespace App\Services\Rental;

use App\Enums\ContractState;
use App\Enums\GuaranteeState;
use App\Enums\IdentityState;
use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\RentalOperation;
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
    public function __construct(
        private SignatureProviderInterface $signatures,
        private ?RentalClosureReadiness $closureReadiness = null,
    ) {}

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
     * Are every mandatory prerequisite for taking money satisfied?
     *
     * The server-side payment gate. Confirmed business rules C-13 and C-14: KYC
     * must be complete BEFORE payment, and the customer must not be able to pay
     * before it. Previously nothing enforced ordering -- the ladder returned
     * the highest rung whose facts held, so an application with no identity
     * record at all could reach Paid.
     *
     * Bank verification is included because it is a mandatory rung of the
     * existing pre-payment chain (BankPending -> BankVerified sits between
     * identity and payment). It is not optional.
     *
     * Returns the blocking reason key, or null when payment may proceed.
     * Callers map the key to a Persian message; the key itself is internal and
     * must not be shown to a customer.
     */
    public function paymentBlockedReason(RentalApplication $application): ?string
    {
        if ($application->state->isTerminal()) {
            return 'application_closed';
        }

        $identity = $application->user?->identity;

        if ($identity === null) {
            return 'identity_missing';
        }

        if ($identity->state !== IdentityState::Verified) {
            return 'identity_not_verified';
        }

        $bankAccounts = $application->user?->bankAccounts;

        if ($bankAccounts === null || $bankAccounts->isEmpty()) {
            return 'bank_missing';
        }

        if (! $bankAccounts->contains(fn ($account) => $account->isVerified())) {
            return 'bank_not_verified';
        }

        if (! $application->hasSelection()) {
            return 'selection_missing';
        }

        return null;
    }

    public function canProceedToPayment(RentalApplication $application): bool
    {
        return $this->paymentBlockedReason($application) === null;
    }

    /**
     * Pure predicate ladder -- no side effects, unit-testable without a
     * database. Because it reads only persisted child state, a re-run after a
     * crash lands on the same answer.
     *
     * ORDERED, not merely "highest true rung". Each rung carries the
     * prerequisites of every rung below it, so a later fact cannot promote an
     * application past a step it never completed. That was the defect behind
     * the payment gate: an order row alone was enough to derive PaymentPending
     * regardless of identity.
     */
    public function nextState(RentalApplication $application): RentalApplicationState
    {
        // Approved is a human decision, not a derived rung, and everything
        // after it (Active, Returned) is produced only by a completed physical
        // operation through transitionPostApproval(). None of them is ever
        // derived, so none may be re-derived either. Without this an ordinary
        // advance() -- the customer merely opening their application page --
        // would recompute the pre-approval ladder from the signed contract and
        // silently move an approved, delivered or returned rental back to
        // AwaitingFinalApproval.
        // A terminal state (Closed, Rejected, Cancelled) is included for the
        // same reason, defensively: advance() already returns early for those
        // and canTransitionTo() refuses to leave them, so this changes no
        // behaviour -- it stops the pure predicate from ever ANSWERING that a
        // closed rental belongs on a pre-approval rung, which is the kind of
        // answer a future caller could act on. Post-close financial work (a
        // retained note's damage paid after closure, C-59) must never move the
        // lifecycle.
        if ($application->state->isTerminal() || in_array($application->state, [
            RentalApplicationState::Approved,
            RentalApplicationState::Active,
            RentalApplicationState::Returned,
        ], true)) {
            return $application->state;
        }

        $identity = $application->user?->identity;
        $order = $application->order;
        $guarantee = $application->guarantee;
        $contract = $application->contract;

        $identityVerified = $identity?->state === IdentityState::Verified;

        $bankAny = ($application->user?->bankAccounts?->isNotEmpty()) ?? false;

        $bankVerified = $application->user?->bankAccounts
            ?->contains(fn ($account) => $account->isVerified()) ?? false;

        // Everything from the selection rung upwards requires a complete KYC
        // chain (C-13/C-14). This single flag is what makes the ladder ordered.
        $kycComplete = $identityVerified && $bankVerified;

        // C-15/C-16: the customer's choice lives on the application, not in a
        // reservation row. No reservation exists before payment clears.
        $hasSelection = $kycComplete && $application->hasSelection();

        $paid = $hasSelection && $order?->payment_status === 'paid';

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

        if ($hasSelection && $order !== null) {
            return RentalApplicationState::PaymentPending;
        }

        // Legacy name, current meaning: the customer has chosen a product and a
        // date range and every payment prerequisite is satisfied. NOTHING is
        // held -- no reservation row exists and no inventory is blocked
        // (C-16). The enum case keeps its stored value `reservation_held` so
        // historical rows and transition ledgers stay readable; renaming it
        // would rewrite decided history for no behavioural gain.
        if ($hasSelection) {
            return RentalApplicationState::ReservationHeld;
        }

        if ($identityVerified && $bankVerified) {
            return RentalApplicationState::BankVerified;
        }

        if ($identityVerified && $bankAny) {
            return RentalApplicationState::BankPending;
        }

        if ($identityVerified) {
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

        // Audited BEFORE the transaction, so a denial survives the throw that
        // would otherwise roll its own audit row back. Re-checked under the
        // lock below, where the throw is what matters and the record already
        // exists.
        $this->assertPhysicalEvidenceFor($application, $target, $actor, audit: true);

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

            $this->assertPhysicalEvidenceFor($locked, $target, $actor, audit: false);

            $from = $locked->state;
            $this->commit($locked, $from, $target, null, $actor);

            $application->setRawAttributes($locked->getAttributes(), true);

            return $application;
        });
    }

    /**
     * The physical fact each post-approval rung REQUIRES, checked here.
     *
     * CONFIRMED RULES: a rental becomes Active only after GamePek physically
     * delivered the device to the customer, and Returned only after GamePek
     * physically received it back. Until now those rules lived in the caller --
     * RentalOperationService only asks for the move after a handover it has
     * just completed -- which is correct but is discipline, not a guard. Any
     * future caller (a command, an import, a new screen) could have moved a
     * rental to Active with no delivery behind it, and the contradiction would
     * only surface later in the reconciler.
     *
     * So the evidence is verified here, under the same lock as the move and
     * inside the same transaction: a COMPLETED operation of the matching type,
     * carrying a handover whose possession actually moved. The legitimate path
     * is unaffected -- the operation is completed and its transfer written
     * before this runs.
     *
     * This invents no policy. It refuses a state whose confirmed precondition
     * is absent, and says so.
     *
     * @param  bool  $audit  true on the pre-transaction pass, so the denial is
     *                       durable; false on the re-check under the lock,
     *                       which must not record it twice.
     *
     * @throws \RuntimeException with a Persian message
     */
    private function assertPhysicalEvidenceFor(
        RentalApplication $application,
        RentalApplicationState $target,
        ?User $actor,
        bool $audit,
    ): void {
        $required = match ($target) {
            RentalApplicationState::Active => RentalOperationType::CustomerDelivery,
            RentalApplicationState::Returned => RentalOperationType::CustomerReturn,
            default => null,
        };

        if ($required === null) {
            return;
        }

        $evidenced = RentalOperation::where('rental_application_id', $application->id)
            ->where('type', $required->value)
            ->where('state', RentalOperationState::Completed->value)
            ->whereHas('custodyTransfer', fn ($q) => $q->possessionMoved())
            ->exists();

        if ($evidenced) {
            return;
        }

        if ($audit) {
            AuditLogger::log(
                action: 'rental_application.transition_denied',
                resourceType: 'RentalApplication',
                resourceId: $application->id,
                result: AuditLogger::RESULT_DENIED,
                context: [
                    'from' => $application->state->value,
                    'to' => $target->value,
                    'missing' => $required->value.'_completed_with_custody_transfer',
                ],
                actor: $actor,
            );
        }

        throw new \RuntimeException($target === RentalApplicationState::Active
            ? 'تا زمانی که تحویل دستگاه به مشتری ثبت و تکمیل نشده باشد، اجاره فعال نمی‌شود.'
            : 'تا زمانی که دریافت دستگاه از مشتری ثبت و تکمیل نشده باشد، اجاره بازگشت‌خورده نمی‌شود.');
    }

    /**
     * Returned -> Closed. The only producer of Closed.
     *
     * Not event-driven (config('rental.lifecycle.closure_trigger') stays null
     * and transitionPostApproval() keeps refusing Closed): closure is an
     * explicit act, allowed only when RentalClosureReadiness reports every
     * blocking prerequisite satisfied -- checked before the transaction so a
     * denial is audited durably, and again under the row lock.
     *
     * Idempotent: an already-closed rental is returned unchanged.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function close(RentalApplication $application, User $actor): RentalApplication
    {
        $this->closureReadiness ??= app(RentalClosureReadiness::class);

        $current = RentalApplication::whereKey($application->id)->firstOrFail();

        if ($current->state === RentalApplicationState::Closed) {
            $application->setRawAttributes($current->getAttributes(), true);

            return $application;
        }

        $report = $this->closureReadiness->check($current);

        if ($current->state !== RentalApplicationState::Returned || ! $report['ready']) {
            AuditLogger::log(
                action: 'rental_application.closure_denied',
                resourceType: 'RentalApplication',
                resourceId: $current->id,
                result: AuditLogger::RESULT_DENIED,
                context: ['state' => $current->state->value, 'missing' => RentalClosureReadiness::missing($report)],
                actor: $actor,
            );

            throw new \RuntimeException('پیش‌نیازهای بستن این اجاره هنوز کامل نشده است.');
        }

        return DB::transaction(function () use ($application, $actor) {
            $locked = RentalApplication::where('id', $application->id)->lockForUpdate()->first();

            if ($locked->state === RentalApplicationState::Closed) {
                $application->setRawAttributes($locked->getAttributes(), true);

                return $application;
            }

            if ($locked->state !== RentalApplicationState::Returned
                || ! $this->closureReadiness->check($locked)['ready']) {
                throw new \RuntimeException('پیش‌نیازهای بستن این اجاره هنوز کامل نشده است.');
            }

            $this->commit($locked, RentalApplicationState::Returned, RentalApplicationState::Closed, 'closure prerequisites satisfied', $actor);

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
