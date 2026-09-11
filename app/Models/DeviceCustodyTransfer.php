<?php

namespace App\Models;

use App\Enums\CustodyActor;
use App\Enums\CustodyTransferState;
use App\Enums\CustodyTransferType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One recorded handover of physical possession.
 *
 * This model never writes to `devices`. Ownership is not its business and it
 * has no method that could change it -- see DeviceCustodyService, which asserts
 * the same thing at the service boundary.
 *
 * NOTHING here is mass-assignable. `$guarded = ['*']` is deliberate and
 * stronger than the usual `['id']`: actors, type, state, owner references and
 * every timestamp are written attribute-by-attribute by DeviceCustodyService,
 * so no request field can ever name itself as the source or destination of a
 * custody transfer, or backdate a handover.
 *
 * This is the one place where a mass-assignment slip would be worst -- a
 * writable `to_actor_type` is a device changing hands on a form post -- so the
 * guard is set to refuse everything rather than to enumerate exceptions.
 */
class DeviceCustodyTransfer extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'from_actor_type' => CustodyActor::class,
            'to_actor_type' => CustodyActor::class,
            'transfer_type' => CustodyTransferType::class,
            'state' => CustodyTransferState::class,
            'initiated_at' => 'datetime',
            'transferred_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * A stable, quotable handle for one handover: CUS-YYMMDD-XXXXXX.
     *
     * OPERATIONAL ONLY. This is NOT a receipt and NOT a legal document. It
     * exists so a specific handover can be named unambiguously on the phone, in
     * an audit trail and in a reconciliation report, the same way
     * `rental_operations.operation_number` names a task.
     *
     * It asserts nothing about legal effect, acceptance, signatures or the
     * condition of the device. Receipt and signature requirements are an open
     * legal gate (docs/business/CONFIRMED_DECISIONS.md section 4.2) and must
     * not be built on top of this without that decision.
     */
    public static function generateReference(): string
    {
        return 'CUS-'.now()->format('ymd').'-'.strtoupper(Str::random(6));
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(RentalOperation::class, 'rental_operation_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(RentalReservation::class, 'rental_reservation_id');
    }

    public function fromOwner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'from_owner_id');
    }

    public function toOwner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'to_owner_id');
    }

    /**
     * CONFIRMED RULE: "The owner has 2 hours after GamePek receives the device
     * to report a defect. After that, GamePek has no responsibility for that
     * defect; disputes are handled through the contracts, receipts and
     * evidence held by GamePek."
     *
     * This is the window's arithmetic and nothing else. Deliberately NOT
     * implemented here, because none of it is decided: no notification is
     * sent, no penalty or charge is computed, no deposit or refund is touched,
     * and no defect-report record type exists yet. A caller gets a deadline
     * and a yes/no; what anyone does about it is not this model's business.
     */
    public const OWNER_DEFECT_REPORT_WINDOW_HOURS = 2;

    /**
     * When the owner's defect-report window closes, or null when there is no
     * window to speak of.
     *
     * SOURCE TIMESTAMP: `transferred_at` of the customer -> GamePek leg, i.e.
     * the moment GamePek recorded receiving the device back from the customer.
     * Only that leg starts the window (see
     * CustodyTransferType::startsOwnerDefectReportWindow()); a delivery, an
     * owner pickup and a return to the owner all answer null.
     *
     * Null also when possession has not moved yet (`transferred_at` is null --
     * the CHECK constraint guarantees it is set once possession moved), so a
     * window can never be computed from a handover that has not happened.
     *
     * The deadline is an absolute instant: Carbon carries the application
     * timezone, so adding two hours is unaffected by how it is later rendered.
     */
    public function ownerDefectReportDeadline(): ?Carbon
    {
        if (! $this->transfer_type->startsOwnerDefectReportWindow()
            || ! $this->isPossessionMoved()
            || $this->transferred_at === null) {
            return null;
        }

        return $this->transferred_at->copy()->addHours(self::OWNER_DEFECT_REPORT_WINDOW_HOURS);
    }

    /**
     * Is $at still inside the window?
     *
     * The window is half-open: [transferred_at, deadline). The deadline instant
     * itself is outside -- "two hours after" has elapsed at that moment. An
     * instant before GamePek received the device is also outside: nothing
     * could be reported about a receipt that had not happened.
     */
    public function isWithinOwnerDefectReportWindow(?Carbon $at = null): bool
    {
        $deadline = $this->ownerDefectReportDeadline();

        if ($deadline === null) {
            return false;
        }

        $at ??= now();

        return $at->greaterThanOrEqualTo($this->transferred_at) && $at->lessThan($deadline);
    }

    /** Has possession actually moved? `requested` means it has not. */
    public function isPossessionMoved(): bool
    {
        return $this->state->isPossessionMoved();
    }

    /** Only meaningful once possession has moved. */
    public function holder(): ?CustodyActor
    {
        return $this->isPossessionMoved() ? $this->to_actor_type : null;
    }

    /**
     * Is the actor pair the one this transfer type actually means?
     *
     * Asserted by the service before every write and enforced again by a CHECK
     * constraint. A row claiming `owner_to_gamepek` while pointing customer ->
     * owner would be a device silently changing hands.
     */
    public function actorsMatchType(): bool
    {
        return $this->from_actor_type === $this->transfer_type->source()
            && $this->to_actor_type === $this->transfer_type->destination();
    }

    public function scopePossessionMoved($query)
    {
        return $query->whereIn('state', [
            CustodyTransferState::Transferred->value,
            CustodyTransferState::Acknowledged->value,
        ]);
    }
}
