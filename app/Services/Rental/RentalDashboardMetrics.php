<?php

namespace App\Services\Rental;

use App\Enums\DeviceOwnership;
use App\Enums\DeviceState;
use App\Enums\RentalApplicationState;
use App\Enums\RentalInspectionStage;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\GuaranteeNoteEvent;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalDamageAssessment;
use App\Models\RentalDamagePayment;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\RentalSettlement;
use App\Models\RentalSettlementCredit;
use Illuminate\Support\Collection;

/**
 * The operational picture of the rental business, for staff. READ-ONLY.
 *
 * Every figure is a real aggregate over real rows -- there is no sampling, no
 * estimate and no placeholder. Nothing here writes, derives a lifecycle state
 * or calls a service that would; opening the dashboard must be as inert as
 * reading a report.
 *
 * TWO RULES THAT SHAPE WHAT MAY APPEAR HERE:
 *
 *  1. A number must be derivable from stored facts. Where a policy is
 *     undecided, the dashboard reports the QUEUE (what is waiting for a human)
 *     and never invents a target, a deadline or a status for it.
 *  2. Money is labelled by what it actually is. `assessed`, `calculated` and
 *     `credited` are three different things and are never summed together. The
 *     late-return fee is CALCULATED ONLY -- its recipient is deferred (C-60),
 *     so it appears as a workload figure, never as revenue, a receivable or
 *     anything payable.
 *
 * QUERY BUDGET. Each section is a fixed handful of aggregates plus, at most,
 * one bounded eager-loaded list. Nothing here loops a query over rows.
 *
 * The one exception is the integrity section, which delegates to
 * OperationCustodyReconciler -- a record-by-record diagnostic whose cost grows
 * with the data by design. It is not reimplemented here (there must be one
 * definition of what a contradiction is), and `snapshot(withIntegrity: false)`
 * exists so the rest can be measured, or rendered, without it.
 */
class RentalDashboardMetrics
{
    /** Rows in any "needs a human" list. Bounded on purpose. */
    public const QUEUE_LIMIT = 10;

    public function __construct(private OperationCustodyReconciler $reconciler) {}

    /**
     * @param  bool  $withIntegrity  the reconciler scans record by record --
     *                               that is its job, and it is the one part of
     *                               this snapshot whose query count grows with
     *                               the data. Everything else is flat. Pass
     *                               false to measure or render without it.
     * @return array<string, mixed>
     */
    public function snapshot(bool $withIntegrity = true): array
    {
        return [
            'integrity' => $withIntegrity ? $this->integrity() : null,
            'applications' => $this->applications(),
            'operations' => $this->operations(),
            'closeout' => $this->closeout(),
            'damage' => $this->damage(),
            'notes' => $this->notes(),
            'settlement' => $this->settlement(),
            'fleet' => $this->fleet(),
            'late' => $this->late(),
            'recent_operations' => $this->recentOperations(),
        ];
    }

    /**
     * Where every application stands, in one grouped query.
     *
     * `awaiting_approval` is the only figure that is a staff QUEUE: final
     * approval is an explicit human decision (B8), so those rentals are
     * waiting on a person and nothing else.
     *
     * @return array<string, mixed>
     */
    private function applications(): array
    {
        $byState = RentalApplication::query()
            ->selectRaw('state, COUNT(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $count = fn (RentalApplicationState $state) => (int) ($byState[$state->value] ?? 0);

        $inProgress = collect(RentalApplicationState::cases())
            ->reject(fn (RentalApplicationState $s) => $s->isTerminal() || in_array($s, [
                RentalApplicationState::AwaitingFinalApproval,
                RentalApplicationState::Approved,
                RentalApplicationState::Active,
                RentalApplicationState::Returned,
            ], true))
            ->sum(fn (RentalApplicationState $s) => $count($s));

        return [
            'in_progress' => (int) $inProgress,
            'awaiting_approval' => $count(RentalApplicationState::AwaitingFinalApproval),
            'approved' => $count(RentalApplicationState::Approved),
            'active' => $count(RentalApplicationState::Active),
            'returned' => $count(RentalApplicationState::Returned),
            'closed' => $count(RentalApplicationState::Closed),
            'cancelled' => $count(RentalApplicationState::Cancelled) + $count(RentalApplicationState::Rejected),
            'awaiting_approval_queue' => RentalApplication::query()
                ->where('state', RentalApplicationState::AwaitingFinalApproval->value)
                ->with(['user:id,full_name', 'reservation:id,rental_application_id,product_id,start_date,end_date', 'reservation.product:id,title_fa'])
                ->latest('id')
                ->limit(self::QUEUE_LIMIT)
                ->get(),
        ];
    }

    /**
     * The physical work queue: every operation that is not finished, by type
     * and by state, in one grouped query.
     *
     * `awaiting_device_allocation` is called out because it is the one step no
     * code may take: which device serves a reservation is a human choice
     * (the allocation rule is undecided), so those tasks sit until someone
     * names a device.
     *
     * @return array<string, mixed>
     */
    private function operations(): array
    {
        $open = RentalOperation::query()
            ->open()
            ->selectRaw('type, state, COUNT(*) as total')
            ->groupBy('type', 'state')
            ->get();

        $byType = fn (RentalOperationType $type) => (int) $open
            ->where('type', $type)
            ->sum('total');

        return [
            'open_total' => (int) $open->sum('total'),
            'awaiting_allocation' => (int) $open
                ->where('state', RentalOperationState::AwaitingDeviceAllocation)
                ->sum('total'),
            'failed' => (int) $open->where('state', RentalOperationState::Failed)->sum('total'),
            'by_type' => [
                'owner_pickup' => $byType(RentalOperationType::OwnerDevicePickup),
                'delivery' => $byType(RentalOperationType::CustomerDelivery),
                'customer_return' => $byType(RentalOperationType::CustomerReturn),
                'owner_return' => $byType(RentalOperationType::OwnerReturn),
            ],
            'queue' => RentalOperation::query()
                ->open()
                ->with([
                    'device:id,serial_number,product_id',
                    'application:id,application_number,state',
                    'reservation:id,product_id',
                    'reservation.product:id,title_fa',
                ])
                ->orderBy('id')
                ->limit(self::QUEUE_LIMIT)
                ->get(),
        ];
    }

    /**
     * Returned rentals and what is still missing before they can be closed.
     *
     * Each figure is one EXISTS/NOT EXISTS aggregate over the returned set --
     * the same facts RentalClosureReadiness reports per rental, counted here
     * without running it once per row.
     *
     * @return array<string, int|Collection>
     */
    private function closeout(): array
    {
        $returned = RentalApplication::query()->where('state', RentalApplicationState::Returned->value);

        $missingInspection = (clone $returned)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('rental_inspections')
                ->whereColumn('rental_inspections.rental_application_id', 'rental_applications.id')
                ->where('stage', RentalInspectionStage::CustomerReturn->value))
            ->count();

        // Damage still open: an assessment above zero with no payment, and no
        // note outcome that resolved it either.
        $unresolvedDamage = (clone $returned)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('rental_damage_assessments')
                ->whereColumn('rental_damage_assessments.rental_application_id', 'rental_applications.id')
                ->where('amount', '>', 0))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('rental_damage_payments')
                ->whereColumn('rental_damage_payments.rental_application_id', 'rental_applications.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('guarantee_note_events')
                ->whereColumn('guarantee_note_events.rental_application_id', 'rental_applications.id')
                ->whereIn('event', [GuaranteeNoteEvent::TRANSFERRED_TO_OWNER, GuaranteeNoteEvent::RETAINED_BY_GAMEPEK]))
            ->count();

        $unresolvedNote = (clone $returned)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('guarantee_note_events')
                ->whereColumn('guarantee_note_events.rental_application_id', 'rental_applications.id')
                ->whereIn('event', [
                    GuaranteeNoteEvent::RETURNED_TO_CUSTOMER,
                    GuaranteeNoteEvent::TRANSFERRED_TO_OWNER,
                    GuaranteeNoteEvent::RETAINED_BY_GAMEPEK,
                ]))
            ->count();

        $awaitingOwnerReturn = (clone $returned)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('rental_reservations')
                ->join('devices', 'devices.id', '=', 'rental_reservations.device_id')
                ->whereColumn('rental_reservations.rental_application_id', 'rental_applications.id')
                ->where('devices.ownership', DeviceOwnership::Owner->value))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('rental_operations')
                ->whereColumn('rental_operations.rental_application_id', 'rental_applications.id')
                ->where('type', RentalOperationType::OwnerReturn->value)
                ->where('state', RentalOperationState::Completed->value))
            ->count();

        return [
            'returned_total' => (clone $returned)->count(),
            'missing_return_inspection' => $missingInspection,
            'unresolved_damage' => $unresolvedDamage,
            'unresolved_note' => $unresolvedNote,
            'awaiting_owner_return' => $awaitingOwnerReturn,
            'queue' => (clone $returned)
                ->with(['user:id,full_name', 'reservation:id,rental_application_id,product_id,device_id', 'reservation.product:id,title_fa'])
                ->latest('id')
                ->limit(self::QUEUE_LIMIT)
                ->get(),
        ];
    }

    /**
     * Damage: what an expert assessed, and what has actually been received.
     *
     * ASSESSED is not a receivable. There is no payment deadline (no rule
     * defines one) and no automatic consequence for an unpaid amount, so the
     * open figure is a workload, not a debt.
     *
     * @return array<string, int>
     */
    private function damage(): array
    {
        // The CURRENT assessment of a rental is its latest row; earlier rows
        // are revisions and must not be counted or summed.
        $currentIds = RentalDamageAssessment::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('rental_application_id')
            ->pluck('id');

        $current = RentalDamageAssessment::query()
            ->whereIn('id', $currentIds)
            ->where('amount', '>', 0);

        $paidAssessmentIds = RentalDamagePayment::query()->select('rental_damage_assessment_id');

        return [
            'assessed_count' => (clone $current)->count(),
            'assessed_amount' => (int) (clone $current)->sum('amount'),
            'unpaid_count' => (clone $current)->whereNotIn('id', $paidAssessmentIds)->count(),
            'unpaid_amount' => (int) (clone $current)->whereNotIn('id', $paidAssessmentIds)->sum('amount'),
            'received_count' => RentalDamagePayment::query()->count(),
            'received_amount' => (int) RentalDamagePayment::query()->sum('amount'),
        ];
    }

    /**
     * Where the physical promissory notes are.
     *
     * Derived exactly as GuaranteeNoteService::statusFor() derives one, but as
     * four aggregates instead of one query per rental. `retained` is the set
     * that stays actionable: the customer may still pay (C-58/C-59).
     *
     * @return array<string, int>
     */
    private function notes(): array
    {
        $withEvent = fn (string|array $events) => GuaranteeNoteEvent::query()
            ->whereIn('event', (array) $events)
            ->distinct()
            ->count('rental_application_id');

        $outcomes = [
            GuaranteeNoteEvent::RETURNED_TO_CUSTOMER,
            GuaranteeNoteEvent::TRANSFERRED_TO_OWNER,
            GuaranteeNoteEvent::RETAINED_BY_GAMEPEK,
        ];

        $held = GuaranteeNoteEvent::query()
            ->where('event', GuaranteeNoteEvent::RECEIVED)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('guarantee_note_events as outcome')
                ->whereColumn('outcome.guarantee_id', 'guarantee_note_events.guarantee_id')
                ->whereIn('outcome.event', $outcomes))
            ->distinct()
            ->count('rental_application_id');

        // Retained AND already returned means the customer paid afterwards
        // and got it back -- that note is home, not still retained.
        $retainedOpen = GuaranteeNoteEvent::query()
            ->where('event', GuaranteeNoteEvent::RETAINED_BY_GAMEPEK)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('guarantee_note_events as later')
                ->whereColumn('later.guarantee_id', 'guarantee_note_events.guarantee_id')
                ->where('later.event', GuaranteeNoteEvent::RETURNED_TO_CUSTOMER))
            ->distinct()
            ->count('rental_application_id');

        return [
            'held' => $held,
            'retained' => $retainedOpen,
            'transferred' => $withEvent(GuaranteeNoteEvent::TRANSFERRED_TO_OWNER),
            'returned' => $withEvent(GuaranteeNoteEvent::RETURNED_TO_CUSTOMER),
        ];
    }

    /**
     * Owner settlements: calculated, and actually credited. Two different
     * facts, never added together and never shown as one "total".
     *
     * @return array<string, int>
     */
    private function settlement(): array
    {
        $creditedIds = RentalSettlementCredit::query()->select('rental_settlement_id');

        return [
            'calculated_count' => RentalSettlement::query()->count(),
            'credited_count' => RentalSettlementCredit::query()->count(),
            'credited_amount' => (int) RentalSettlementCredit::query()->sum('amount'),
            'awaiting_credit_count' => RentalSettlement::query()->whereNotIn('id', $creditedIds)->count(),
            'awaiting_credit_amount' => (int) RentalSettlement::query()->whereNotIn('id', $creditedIds)->sum('owner_share'),
        ];
    }

    /**
     * The physical fleet and its capacity.
     *
     * "With customers" is derived from the blocking reservation of a running
     * rental rather than from Device::currentCustody(), which would be one
     * query per device for the same answer.
     *
     * @return array<string, int>
     */
    private function fleet(): array
    {
        $rentable = Device::query()->rentable();

        $withCustomers = RentalReservation::query()
            ->blocking()
            ->whereNotNull('device_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('rental_applications')
                ->whereColumn('rental_applications.id', 'rental_reservations.rental_application_id')
                ->where('state', RentalApplicationState::Active->value))
            ->distinct()
            ->count('device_id');

        // A rentable product with no eligible device can never be booked. It
        // is a capacity gap, not an error, and a human decides what to do.
        $productsWithoutDevices = Product::query()
            ->where('attributes->_rental->status', 'available')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('devices')
                ->whereColumn('devices.product_id', 'products.id')
                ->where('devices.state', DeviceState::Approved->value))
            ->count();

        return [
            'rentable' => (clone $rentable)->count(),
            'with_customers' => $withCustomers,
            'pending_approval' => Device::query()->where('state', DeviceState::PendingReview->value)->count(),
            'disabled' => Device::query()->where('state', DeviceState::Disabled->value)->count(),
            'products_without_devices' => $productsWithoutDevices,
        ];
    }

    /**
     * Overdue rentals: the device is past its contractual end date and has not
     * come back (C-57). The device stays blocked until it does.
     *
     * The late FEE is deliberately absent from this section. It is calculated
     * per rental (App\Support\Rental\LateReturn) but its recipient is deferred
     * (C-60), so no total is presented here that could read as money owed to
     * or by anyone.
     *
     * @return array<string, int|Collection>
     */
    private function late(): array
    {
        $overdue = RentalReservation::query()
            ->blocking()
            ->whereNull('returned_on')
            ->whereDate('end_date', '<', now()->toDateString())
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('rental_applications')
                ->whereColumn('rental_applications.id', 'rental_reservations.rental_application_id')
                ->where('state', RentalApplicationState::Active->value));

        return [
            'overdue_count' => (clone $overdue)->count(),
            'queue' => (clone $overdue)
                ->with([
                    'application:id,application_number,user_id,state',
                    'application.user:id,full_name',
                    'product:id,title_fa',
                    'device:id,serial_number',
                ])
                ->orderBy('end_date')
                ->limit(self::QUEUE_LIMIT)
                ->get(),
        ];
    }

    /**
     * Contradictions the reconciler can see. READ-ONLY, and reported only --
     * the dashboard offers no repair, because every repair is an undecided
     * business question.
     *
     * @return array<string, mixed>
     */
    private function integrity(): array
    {
        $findings = $this->reconciler->findings();

        return [
            'total' => $findings->count(),
            'by_code' => $findings->groupBy('code')->map->count()->sortDesc(),
            'sample' => $findings->take(self::QUEUE_LIMIT)->values(),
        ];
    }

    /** The last completed physical movements, eager loaded. */
    private function recentOperations(): Collection
    {
        return RentalOperation::query()
            ->where('state', RentalOperationState::Completed->value)
            ->with([
                'device:id,serial_number,product_id',
                'application:id,application_number',
                'completedBy:id,full_name',
            ])
            ->latest('completed_at')
            ->limit(self::QUEUE_LIMIT)
            ->get();
    }
}
