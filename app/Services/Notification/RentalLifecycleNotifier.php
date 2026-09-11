<?php

namespace App\Services\Notification;

use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Turns a committed rental state change into a customer SMS -- when, and only
 * when, a message has been approved for it.
 *
 * SMS-05. The map is config('rental.sms.state_templates'): target state value
 * => template key, with the template bodies in config('rental.sms.templates').
 * BOTH SHIP EMPTY (TODO(business) B13): no customer copy is approved, so today
 * this notifier does nothing at all. It is the wiring, ready for the copy.
 *
 * THREE GUARANTEES, each a technical necessity rather than a business rule:
 *
 *  1. AFTER COMMIT. A transition that rolls back never produces a message, and
 *     sending happens outside the transaction that changed the state.
 *  2. ISOLATED. Nothing an SMS provider does -- refuse, time out, throw -- can
 *     reach the lifecycle, custody, reservation, wallet or settlement: every
 *     failure is caught here, recorded, and swallowed.
 *  3. ONCE. The message is keyed `transition:{id}` and sms_messages carries a
 *     unique index on that key, so one state change can never send twice.
 *
 * Unmapped transitions are silent -- an empty map is the feature switched off,
 * not a fault worth an audit row per state change.
 */
class RentalLifecycleNotifier
{
    public function __construct(private SmsService $sms) {}

    /** Called for every new transition row; defers to after the commit. */
    public function transitionRecorded(RentalApplicationTransition $transition): void
    {
        $templateKey = config('rental.sms.state_templates.'.$transition->to_state);

        if (! $templateKey) {
            return;
        }

        DB::afterCommit(fn () => $this->send($transition, (string) $templateKey));
    }

    private function send(RentalApplicationTransition $transition, string $templateKey): void
    {
        try {
            $application = RentalApplication::with('user:id,mobile')
                ->find($transition->rental_application_id);

            $mobile = $application?->user?->mobile;

            if (! $mobile) {
                return;
            }

            // Only what a customer message could need, and nothing internal:
            // no state codes, no amounts, no notes.
            $this->sms->send(
                $templateKey,
                $mobile,
                ['application_number' => $application->application_number],
                $application->user,
                dedupeKey: 'transition:'.$transition->id,
            );
        } catch (\Throwable $e) {
            AuditLogger::log(
                action: 'sms.dispatch_failed',
                resourceType: 'RentalApplicationTransition',
                resourceId: $transition->id,
                result: AuditLogger::RESULT_FAILURE,
                // The exception class, never its message: provider errors can
                // carry request URLs or payload fragments.
                context: ['template_key' => $templateKey, 'error' => $e::class],
            );

            report($e);
        }
    }
}
