<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Services\Audit\AuditLogger;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalOperationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The operations queue: pickup tasks and the custody records under them.
 *
 * Permission-checked explicitly on every action rather than relying on the
 * Gate::before admin bypass -- CLAUDE.md requires admin screens to check and
 * audit in their own right, and the audit found routes reachable by direct POST
 * that only looked protected.
 *
 * Thin by design. Every rule lives in RentalOperationService and
 * DeviceCustodyService; nothing here writes a lifecycle field. Note in
 * particular what is NOT accepted from a request: operation state, custody
 * source or destination, actor, owner, timestamps. A device may be NAMED, and
 * the service decides whether that device is allowed.
 *
 * Scope is deliberately narrow: list, inspect, attach a device, schedule,
 * start, record receipt, mark failed. Inspection, delivery, return and
 * settlement screens belong to later phases.
 */
class OperationController extends Controller
{
    public function __construct(
        private RentalOperationService $operations,
        private DeviceCustodyService $custody,
    ) {}

    public function index(Request $request)
    {
        abort_if(! $request->user()->can('view_operations'), 403);

        $operations = RentalOperation::with(['device.product', 'owner.user', 'application', 'assignedTo'])
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->get('state')))
            ->when($request->boolean('open'), fn ($q) => $q->open())
            ->when($request->filled('q'), fn ($q) => $q->where('operation_number', 'like', '%'.$request->get('q').'%'))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.operations.index', [
            'operations' => $operations,
            'states' => RentalOperationState::cases(),
        ]);
    }

    public function show(Request $request, RentalOperation $operation)
    {
        abort_if(! $request->user()->can('view_operations'), 403);

        $operation->loadMissing([
            'device.product', 'owner.user', 'application.user',
            'reservation', 'assignedTo', 'completedBy',
            'custodyTransfer.fromOwner.user',
        ]);

        // Candidate devices for an unallocated task. This is a LIST for a human
        // to choose from -- nothing here ranks, defaults or preselects, because
        // the allocation rule is undecided (POLICY GATE).
        //
        // Read-side safety aid only: devices already committed to another
        // blocking reservation for an overlapping date range are excluded so
        // an obviously conflicting device is not even offered. This is NOT the
        // security boundary -- RentalOperationService::attachDevice() re-checks
        // and refuses the same conflict authoritatively, under a lock, even if
        // this list were somehow bypassed. Reuses the one existing overlap
        // predicate and blocking-state definition (RentalReservation); no
        // second overlap concept, no ranking, no automatic selection.
        $candidates = collect();

        if (! $operation->hasDevice() && ! $operation->isTerminal() && $operation->reservation) {
            $reservation = $operation->reservation;

            $conflictingDeviceIds = RentalReservation::overlapping(
                $reservation->product_id,
                $reservation->start_date->toDateString(),
                $reservation->end_date->toDateString(),
            )
                ->where('id', '!=', $reservation->id)
                ->whereNotNull('device_id')
                ->blocking()
                ->pluck('device_id');

            $candidates = Device::with('owner.user')
                ->where('product_id', $reservation->product_id)
                ->rentable()
                ->whereNotIn('id', $conflictingDeviceIds)
                ->orderBy('id')
                ->get();
        }

        // The audit trail for this task and its handover, read from the
        // existing audit_events table. No second audit mechanism is
        // introduced; this is a view onto the one the project already has.
        $auditEvents = AuditEvent::query()
            ->where(function ($q) use ($operation) {
                $q->where(fn ($q) => $q->where('resource_type', 'RentalOperation')
                    ->where('resource_id', $operation->id));

                if ($operation->custodyTransfer) {
                    $q->orWhere(fn ($q) => $q->where('resource_type', 'DeviceCustodyTransfer')
                        ->where('resource_id', $operation->custodyTransfer->id));
                }
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('admin.operations.show', compact('operation', 'candidates', 'auditEvents'));
    }

    /**
     * Operations and custody records that contradict each other.
     *
     * READ-ONLY. Nothing here repairs anything: every plausible repair is an
     * undecided business question, and guessing would destroy the evidence a
     * human needs. See OperationCustodyReconciler.
     */
    public function reconciliation(Request $request, OperationCustodyReconciler $reconciler)
    {
        abort_if(! $request->user()->can('view_operations'), 403);

        return view('admin.operations.reconciliation', [
            'findings' => $reconciler->findings(),
        ]);
    }

    /** Attach the physical device an operator chose. Nothing is auto-selected. */
    public function attachDevice(Request $request, RentalOperation $operation)
    {
        abort_if(! $request->user()->can('manage_operations'), 403);

        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:devices,id'],
        ], ['device_id.required' => 'انتخاب دستگاه الزامی است.']);

        try {
            $this->operations->attachDevice($operation, Device::findOrFail($data['device_id']), $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'دستگاه به این عملیات تخصیص یافت.');
    }

    public function schedule(Request $request, RentalOperation $operation)
    {
        abort_if(! $request->user()->can('manage_operations'), 403);

        $data = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->operations->schedule(
                $operation,
                $request->user(),
                $data['scheduled_at'] ?? null,
                $data['assigned_to_user_id'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'زمان‌بندی عملیات ثبت شد.');
    }

    /**
     * Begin the pickup and open the custody request in one go.
     *
     * Both writes share a transaction: a started pickup with no custody request
     * behind it would leave the owner with nothing to see and the operator with
     * nothing to complete.
     */
    public function start(Request $request, RentalOperation $operation)
    {
        abort_if(! $request->user()->can('manage_operations'), 403);

        try {
            DB::transaction(function () use ($request, $operation) {
                $this->operations->start($operation, $request->user());
                $this->custody->requestFromOwner($operation, $request->user());
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'عملیات آغاز شد و درخواست تحویل برای مالک ثبت شد.');
    }

    /**
     * Record that GamePek physically received the device.
     *
     * This is the moment custody moves; ownership does not, and the service
     * asserts that rather than assuming it.
     */
    public function recordCustody(Request $request, RentalOperation $operation)
    {
        abort_if(! $request->user()->can('manage_operations'), 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->custody->recordHandoverToGamePek($operation, $request->user(), $data['notes'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تحویل دستگاه ثبت شد و عملیات تکمیل شد.');
    }

    /**
     * POLICY GATE: recording a failure does nothing else. No refund, no owner
     * penalty, no replacement device, no cancellation. Those are undecided.
     */
    public function fail(Request $request, RentalOperation $operation)
    {
        abort_if(! $request->user()->can('manage_operations'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'ثبت دلیل ناموفق بودن عملیات الزامی است.']);

        try {
            $this->operations->fail($operation, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'ناموفق بودن عملیات ثبت شد.');
    }

    /** Custody history for one device: who has held it, and when. */
    public function custodyHistory(Request $request, Device $device)
    {
        abort_if(! $request->user()->can('view_operations'), 403);

        $device->loadMissing(['product', 'owner.user']);

        $transfers = $device->custodyTransfers()
            ->with(['operation', 'fromOwner.user'])
            ->latest('id')
            ->get();

        // Reading a device's custody history exposes its serial and its owner's
        // identity, so the read itself is audited -- the same rule the device
        // and verification screens follow.
        AuditLogger::log(
            action: 'custody.history_viewed',
            resourceType: 'Device',
            resourceId: $device->id,
            context: ['serial_mask' => $device->maskedSerial(), 'owner_id' => $device->owner_id],
            actor: $request->user(),
        );

        return view('admin.operations.custody', [
            'device' => $device,
            'transfers' => $transfers,
            'currentCustody' => $device->currentCustody(),
            'pickupType' => RentalOperationType::OwnerDevicePickup,
        ]);
    }
}
