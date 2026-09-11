# Operations and custody

<div dir="rtl">

> دامنهٔ عملیات فیزیکی و سابقهٔ تحویل دستگاه. نام کد، فیلد و اصطلاح فنی عمداً
> انگلیسی مانده تا قابل جستجو باشد.

</div>

**Scope of this document:** what the operations and custody domain does *today*.
Everything it does not do is listed explicitly, because "code exists" is not the
same as "the feature is complete."

---

## 1. The one thing to take away

**Custody is not ownership.**

| Fact | Where it lives | Who changes it |
|---|---|---|
| Who **owns** a device | `devices.owner_id` + `devices.ownership` | `DeviceRegistrationService`, at registration only |
| Who **holds** a device right now | derived from `device_custody_transfers` | `DeviceCustodyService` |

An owner who hands GamePek a console for a rental has not sold it. `owner_id`
and `ownership` are never written by the custody path, and
`DeviceCustodyService` asserts after every write that neither moved — the
assertion fails loudly rather than transferring someone's console.

There is deliberately **no `devices.current_custody` column**.
`Device::currentCustody()` derives it from the latest transfer that actually
means possession moved, and falls back to the owner implied by `ownership` when
there is none. A stored copy and a derived value are two places to disagree.

---

## 2. The operation domain

A `RentalOperation` is one unit of real physical work attached to a **paid**
reservation.

- Created inside the **same transaction** as the reservation
  (`RentalReservationService::materialiseAfterPayment()`), so an operation can
  never exist for a reservation that failed to be written, and an unpaid or
  failed payment produces neither.
- Idempotent: an existing task is returned untouched, and
  `unique(rental_reservation_id, type)` catches the concurrent case. A replayed
  gateway callback yields exactly one task.
- `state`, `type`, `device_id`, `owner_id` and every lifecycle timestamp are
  **not fillable**. `RentalOperationService` is the only writer of `state`, the
  same single-writer discipline `RentalChainOrchestrator` applies to the
  application chain.

Only one operation type exists: `owner_device_pickup`. Delivery, customer
return, owner return and inspection are real future operations and are
**absent**, not declared-and-disabled.

### Operation states

```
pending
  └─ awaiting_device_allocation ─┬─ scheduled ─┬─ in_progress ─┬─ completed
                                 │             │               └─ failed ─┐
                                 │             └─ failed                  │
                                 └─ not_required          (retry) ────────┘
```

`completed` and `not_required` are terminal. A completed pickup is never
reopened: there is no correction workflow, and inventing one would let a
recorded handover be silently undone.

| State | Means |
|---|---|
| `pending` | created, nothing evaluated yet |
| `awaiting_device_allocation` | no physical device is attached, so no pickup can be planned |
| `scheduled` | a concrete device is attached |
| `in_progress` | GamePek has asked the owner for the device |
| `completed` | GamePek recorded physical receipt |
| `failed` | the pickup did not happen; reason recorded |
| `not_required` | a **GamePek-owned** device was attached, so there is nothing to pick up |

---

## 3. Device dependency — nothing is guessed

Which free device serves a paid reservation is an **undecided policy** (see
`CLAUDE.md` §10.3b). It interacts with the 35/65 split and daily settlement,
and guessing it would quietly pick winners among owners.

So:

- A pickup task is born with `device_id = NULL` and sits in
  `awaiting_device_allocation`. It says so instead of picking one.
- `RentalOperationService::attachDevice()` validates the device it is **given**
  by a human. There is no overload that finds a device on its own. **Do not add
  one.**
- A pickup cannot be started or completed without a concrete device, and
  `CHECK (state <> 'completed' OR device_id IS NOT NULL)` enforces that in the
  database.

Attaching a device also writes `rental_reservations.device_id`, so the
reservation and its operation can never name two different consoles. The
allocation *policy* is unchanged: still an explicit human choice, never
automatic.

### 3.1 Device-level overlap safety (implemented; still not a selection policy)

`attachDevice()` also refuses to attach a device that is already committed to
a **different** blocking reservation whose dates overlap the one being
attached to. This is a **safety check, not a selection rule**: it never
chooses a device, ranks candidates, or decides who gets one — it only refuses
an attachment that would double-book a specific physical unit. It:

- locks the candidate `Device` row (`lockForUpdate()`) so two concurrent
  attachments naming the same device serialise, exactly like two racing
  payments already serialise on the product row in
  `RentalReservationService::materialiseAfterPayment()`;
- reuses the one existing overlap predicate
  (`RentalReservation::scopeOverlapping()`) and the one existing
  blocking-state definition (`scopeBlocking()`) with a `device_id` filter
  layered on top — there is still exactly one overlap concept in the
  codebase, and product-level availability (`RentalAvailabilityService`) is
  untouched;
- is audited as `operation.device_attach_denied` (`result: denied`) when it
  refuses, distinct from the existing `operation.device_attached` success
  event.

`Admin\OperationController::show()`'s candidate list also excludes devices
that would obviously conflict with the reservation's dates, as a read-side
convenience — `attachDevice()` remains the sole authority; the candidate
list is not a security boundary.

**Still undecided, unchanged by this:** which device to prefer when several
are free (GamePek-first vs. owner-rotation vs. condition), what happens after
a failed pickup, and whether a product can even have more than one
concurrently-blocking reservation at all (today it cannot — see §10).

---

## 4. GamePek-owned devices

A GamePek-owned device is already in GamePek custody.

- **No owner pickup operation.** Attaching such a device resolves the task to
  `not_required`, not `completed` — no handover happened, because none was
  needed.
- **No GamePek → GamePek transfer row.** Writing one would record a handover
  nobody performed. `Device::currentCustody()` covers the case with no row.
- **No fake owner account.** `ownership = gamepek` with a NULL `owner_id`,
  bound by a CHECK constraint on `devices`.

Attempting a handover on GamePek stock is refused with a Persian message.

---

## 5. Custody states

```
requested ─→ transferred ─→ acknowledged
```

| State | Means | Possession moved? |
|---|---|---|
| `requested` | GamePek asked the owner for the device | **No** |
| `transferred` | GamePek recorded taking physical possession | **Yes** |
| `acknowledged` | the owner confirmed GamePek's record in their own panel | already had |

The operation completes at `transferred`, not at `acknowledged` — GamePek's
queue must not block on the owner.

Actors are `owner`, `gamepek` and `customer`. Only `owner_to_gamepek` is
executable. `gamepek → customer`, `customer → gamepek` and `gamepek → owner`
are real future legs and are unimplemented.

---

## 5b. The transfer reference — not a receipt

Every custody transfer carries a `reference_number` shaped `CUS-YYMMDD-XXXXXX`,
parallel to `rental_operations.operation_number`.

It is an **internal operational handle** so a specific handover can be named on
the phone, in the audit trail and in the reconciliation report. It asserts
nothing about legal effect, acceptance, signatures, or the condition of the
device.

The business will eventually need real custody receipts for all four legs. Their
legal structure is unspecified and requires legal review, so **do not build
receipt semantics on top of this field** until that gate is decided.

---

## 6. Concurrency, idempotency and database invariants

- `unique(rental_reservation_id, type)` on `rental_operations` — one pickup per
  reservation, forever.
- `unique(rental_operation_id)` on `device_custody_transfers` — one handover per
  task.
- `unique(reference_number)` — one handle per handover.
- Every transition locks its row with `lockForUpdate()` first. Two operators
  pressing "received" at once produce exactly one custody record; the loser gets
  a safe Persian conflict message.

CHECK constraints, because the service being the only writer is a fact about
today's code rather than an invariant:

| Constraint | Refuses |
|---|---|
| `rental_operations_completed_device_ck` | a completed operation with no device |
| `device_custody_transferred_at_ck` | possession moved with no timestamp |
| `device_custody_acknowledged_at_ck` | an acknowledgement with no timestamp |
| `device_custody_actor_pair_ck` | `owner_to_gamepek` pointing anywhere but owner → gamepek |
| `device_custody_owner_ref_ck` | an owner side naming no owner, or a GamePek/customer side naming one |

The last one also blocks inventing a fake owner account for GamePek stock
through the custody table.

The operation-and-transfer device agreement spans two tables, which a CHECK
cannot express in MariaDB. It is asserted in `DeviceCustodyService` and detected
after the fact by the reconciler below. A trigger was considered and rejected: a
hidden write-path side effect is harder to reason about than an explicit
assertion plus a report an operator can read.

---

## 6b. Reconciliation

`OperationCustodyReconciler` reports operations and custody records that have
drifted into contradiction: a completion with no handover, a completion whose
device is not in GamePek custody, an operation and transfer naming different
consoles, a handover whose operation never closed, an actor pair that
contradicts its type.

Every one of those is something the write path already prevents. That is exactly
why the report is worth having — records reach that state through the paths no
service controls: a console command, an import, a hand-run UPDATE during an
incident, a future bug, or rows written before a guard existed.

**It is read-only and repairs nothing.** Every plausible repair is an undecided
business question, and guessing would destroy the evidence that makes the
contradiction fixable by a human. Admin reads it at
`/admin/operations/reconciliation` under `view_operations`.

---

## 7. Audit

Actions recorded through the existing `AuditLogger`: `operation.created`,
`operation.awaiting_device_allocation`, `operation.device_attached`,
`operation.device_attach_denied` (device-overlap refusal, `result: denied`),
`operation.scheduled`, `operation.started`, `operation.completed`,
`operation.failed`, `custody.requested`, `custody.transferred`,
`custody.acknowledged`, `custody.history_viewed`.

**Raw serial numbers never appear in audit context.** Only
`device_serial_mask` is recorded, matching the discipline `device.registered`
already follows. Ownership is recorded alongside custody in the transfer events,
so the trail shows plainly that one moved and the other did not.

---

## 8. Authorization

| Actor | May |
|---|---|
| `view_operations` | list and inspect operations, read custody history |
| `manage_operations` | attach a device, schedule, start, record receipt, mark failed |
| Device owner | view **their own** pickup and custody status, acknowledge the recorded handover |

`RentalOperationPolicy::manage()` returns `false` for everyone: an owner can
never schedule, start, complete or fail a task, or name GamePek as having
received a device it has not received. Admin screens check their own permission
explicitly rather than leaning on the `Gate::before` admin bypass.

The owner screens expose the device, the rental dates and the handover state.
They expose **no customer identity, contact or payment data**, and no device
belonging to another owner.

---

## 9. Not implemented — do not read these as done

- Condition grading, damage taxonomy, inspection approval or rejection
  (free-text inspection evidence exists -- §14)
- Courier or third-party delivery
- Damage valuation and lost device (late return IS implemented -- §18 --
  though the destination of its fee is not decided, so nothing is charged)
- Refund, cancellation and deposit rules (there is no deposit at all, C-49).
  Settlement, the promissory note and the wallet ARE implemented -- §15, §16
- Owner penalties of any kind
- SMS on any operational event
- Receipts, documents or signatures for a handover. `reference_number` is an
  internal handle and is **not** a receipt (§5b)
- Automatic repair of any contradiction the reconciler reports (§6b)
- Releasing a device from GamePek custody

## 10. Open gates relevant to this domain

- **Device allocation rule** — undecided; nothing chooses a device. §3.1's
  overlap check only refuses an unsafe manual choice — it does not decide
  GamePek-first vs. owner-rotation vs. condition-based selection, and does
  not make selection automatic
- **Owner fairness / rotation** — undecided
- **Condition-based selection** — undecided; `devices.condition` is free text
- **Product capacity model** — undecided; `RentalAvailabilityService` treats
  each product as one concurrently-blockable slot regardless of how many
  `Device` rows are registered for it, so today two blocking reservations
  cannot even coexist for one product. Whether that should change for a
  multi-unit product, and how, is unresolved
- **Allocation timing relative to final approval** — undecided; today a
  device may be attached as soon as the pickup task exists (right after
  payment), long before `Approved`. Nothing gates it on approval
- **What follows a failed pickup** — undecided; the failure is recorded and
  nothing else happens
- **Receipt / signature requirements for a handover** — open legal gate;
  `acknowledged` claims no legal effect
- **Releasing a device from GamePek custody without a completed rental** — no
  such transition exists

See `docs/business/CONFIRMED_DECISIONS.md` §4 for the full register.

## 11. Foundation hardening audit (no behaviour changed)

A full audit of this domain -- `RentalOperationService`, `DeviceCustodyService`,
`OperationCustodyReconciler`, `Admin\OperationController`,
`OwnerOperationController`, `RentalOperationPolicy`, and every route in
`owner/operations` and `admin/operations` -- found the state machine,
authorization, locking, idempotency and audit trail already sound. No security
or validation defect was found; no code in this domain changed as a result.
What the audit added was test coverage for paths that had none:

- `RentalOperationService::schedule()` had zero test coverage at all -- now
  covered, both the happy path and its state guard.
- `start()` on an already-`in_progress` operation was untested -- now
  covered (refused, no side effect).
- `DeviceCustodyService::acknowledgeByOwner()` had no test for a transfer
  still in `requested` (possession not yet moved) -- now covered at both the
  service and the `owner.operations.acknowledge` route.
- `OperationCustodyReconciler::ACTOR_PAIR_MISMATCH` remains untestable at the
  integration level on purpose: `CustodyTransferType` has exactly one case
  and the `device_custody_actor_pair_ck` database constraint (§6) refuses
  the mismatched row even via a raw, service-bypassing write. Only the
  underlying `DeviceCustodyTransfer::actorsMatchType()` predicate is unit
  tested; a full reconciler-level test would require deliberately violating
  that constraint, which was not done.

**Customer-facing operation/custody status was deliberately NOT added.**
`RentalOperation`/`DeviceCustodyTransfer` model exactly one leg: the owner's
device reaching GamePek. There is no GamePek → customer leg (§2, §5).
Showing a customer anything derived from this data -- "pickup: scheduled",
"in progress" -- would misrepresent an owner-side logistics fact as if it
were the customer's own delivery status, when no such customer-facing
delivery process exists in the codebase at all. This is judged unsafe/
misleading rather than merely incomplete, so nothing was added to the
customer-facing Rental Application page.

## 13. Delivery, activation and customer return -- IMPLEMENTED

The business has since confirmed the rules §12 was waiting on, and the
delivery and customer-return legs are now built. §12 is kept below as the
record of why they were not built earlier; it is superseded by this section.

### 13.1 The confirmed rules, implemented

| Rule | Where it lives |
|---|---|
| A rental becomes **Active only when GamePek physically delivers the device to the customer** -- never because the start date arrived | completing `customer_delivery` calls `RentalChainOrchestrator::transitionPostApproval()`; `config('rental.lifecycle.activation_trigger')` names it |
| Delivery is performed by **GamePek** | the delivery task is staff-driven; there is no courier concept and no provider field |
| Delivery carries a **receipt/acceptance and a customer signature** | the handover's `reference_number` (`CUS-…`) is the receipt handle recorded at the door; the customer can separately confirm the record in their own panel |
| The device is **checked and diagnosed at the customer's door** | the condition record is captured as free text on the transfer's `notes` when the delivery is recorded |
| The customer **returns the device to GamePek** | `customer_return`, opened and recorded by staff |
| The return is **coordinated through support** | there is deliberately NO customer-facing control to start a return -- only to confirm a handover record |
| Completing the return moves the rental to **Returned** | `config('rental.lifecycle.return_trigger')` |
| The owner has **2 hours after GamePek receives the device** to report a defect | `DeviceCustodyTransfer::ownerDefectReportDeadline()` / `isWithinOwnerDefectReportWindow()` -- arithmetic only |

### 13.2 New custody legs

```
owner_to_gamepek     owner    -> gamepek   (unchanged)
gamepek_to_customer  gamepek  -> customer  (new: starts the rental)
customer_to_gamepek  customer -> gamepek   (new: ends it)
```

Each leg still moves possession only at `transferred`; `acknowledged` remains
a confirmation of the record by the counterparty and carries no legal effect.
Both counterparties are now served: `acknowledgeByOwner()` accepts only the
owner leg, `acknowledgeByCustomer()` only the customer legs and only for the
customer who owns that rental.

`device_custody_actor_pair_ck` was tightened in the same change: it now pins
each of the three types to its own actor pair AND restricts `transfer_type`
to those three. The previous form was vacuously true for any type other than
`owner_to_gamepek`, so the moment a second leg existed the database would
have stopped checking actor pairs for it.

A leg also refuses to open unless the side giving the device up is actually
holding it (`Device::currentCustody()`): you cannot deliver a console you
never collected, or take one back from a customer who never received it.

### 13.3 Still NOT decided -- do not read any of this as settled

- **The penalty when a customer refuses delivery.** Confirmed that one
  exists; the amount and its calculation are not defined, so nothing is
  charged, computed or recorded beyond the failure itself.
- **What follows a failed delivery.** Handled case by case with the customer;
  no automatic consequence is implemented.
- **Damage.** A GamePek expert determines the amount when damage exists.
  There is no damage taxonomy, severity scale, repair pricing or automatic
  assessment -- the delivery/return condition record is free text.
- **What happens financially after the owner's two-hour window closes.** The
  window is calculated and nothing acts on it: no notification, no penalty,
  no deposit or refund movement.
- **Deposit, refund, settlement.** Untouched.
- **Closure (`Returned -> Closed`).** Its trigger is still null and the
  transition still refuses with `rental_application.policy_undefined`.
- **Whether a digital signature may replace the signed paper receipt.** The
  requirement is confirmed; the mechanism is not, and nothing in the system
  claims the in-app confirmation is a signature.
- ~~`gamepek_to_owner`~~ and ~~inspection~~ -- both since built; see §14.

## 14. Inspection, return hardening and the owner return -- IMPLEMENTED

### 14.1 Confirmed behaviour now in code

| Rule | Where it lives |
|---|---|
| The device eventually goes back from GamePek to its owner (C-40) | `owner_return` operation + `gamepek_to_owner` leg; staff-opened only once the rental is `Returned`, owner devices only |
| A customer never hands a device straight to its owner | there is no `customer_to_owner` type; `device_custody_actor_pair_ck` refuses one, and every leg requires its source side to hold the device |
| The owner's two-hour window runs from GamePek receiving the device back (C-38) | only `customer_to_gamepek` starts it (`CustodyTransferType::startsOwnerDefectReportWindow()`); shown on the admin operation screen; nothing acts on it |
| GamePek's expert determines damage (C-39) | supported as evidence only: `rental_inspections`, free text, no amount |

### 14.2 Technical foundations (not business decisions)

- **Inspection record.** `rental_inspections` is append-only (model refuses
  update/delete, no `updated_at`), fully guarded against mass assignment, and
  written only by `RentalInspectionService`. Device, reservation, application,
  handover and stage are derived from the operation under a row lock and
  cross-checked; nothing is taken from the request except `findings`. Only
  `customer_delivery` and `customer_return` are inspectable, and only after
  their handover is recorded. The handover's own `notes` stays the door
  check; inspections add later evidence (e.g. the expert's diagnosis).
  Findings are staff-only and never rendered to customers or owners.
- **Return hardening.** A customer return must follow THIS rental's own
  delivery (the device's latest movement must be that delivery); an owner
  return must follow THIS rental's own customer return and name the device's
  real owner. Cross-rental evidence is refused.
- **Custody-aware pickups (bug fix).** Once the lifecycle is circular an
  owner's console may still be with GamePek between rentals. `attachDevice()`
  now resolves such a pickup to `not_required`, and the owner pickup refuses
  to record a handover unless the owner actually holds the device -- it
  previously could record an owner -> GamePek handover nobody made.
  Limitation: a pickup attached while the device is with another customer
  stays `scheduled` and cannot complete; staff mark it failed.
- **Owner acknowledgement** now covers both owner legs and re-checks, inside
  the service, that the acting user is the owner named on the transfer.
- **Reconciler** covers all four legs (new `completed_but_custody_not_owner`)
  and inspections (`inspection_reference_mismatch`). Still read-only.

### 14.3 Lifecycle and availability -- deliberately unchanged

- `Returned` is stable: `advance()` no longer re-derives any post-approval
  state (bug fix -- it previously moved Active/Returned rentals back to
  `AwaitingFinalApproval` whenever an application page was viewed). No code
  path produces `Closed`; `closure_trigger` stays null.
- **Technical prerequisites a future closure will need** (none decided): a
  deposit release rule (B4), a damage outcome that can be recorded as final
  (C-39 amount), a media-retention rule (B11), and a statement of whether
  closure waits for the owner return or the two-hour window. The extension
  point is `rental.lifecycle.closure_trigger` plus one more arm in
  `RentalOperationService::advanceLifecycleAfter()` or a dedicated service
  calling `transitionPostApproval()` -- nothing else needs to change.
- **Reservation state is not advanced by operations** and stays `paid`
  through delivery, return and owner return. Blocking is date-bounded, so
  nothing blocks forever; an early return still blocks the rest of its booked
  range. Releasing that remainder is an availability policy (and interacts
  with the product capacity model, §10), so it was left unchanged.

### 14.4 Consistency between consecutive rentals of one console

Technical guards, not allocation policy -- neither picks a device:

- An **owner return is refused** while a later, not-yet-delivered rental's
  pickup was closed as `not_required` because GamePek held this device. Sending
  it home would leave that rental with no device and a closed pickup. Which of
  the two should win is an allocation decision for a human.
- **`attachDevice()` refuses a device with an open owner return** (scheduled,
  in progress or failed-and-retryable). Otherwise the pickup would read "GamePek
  has it", close as `not_required`, and then lose the device.

Once the owner return completes, the next rental's pickup is a real
owner -> GamePek collection again.

Other hardening in the same batch: the customer page keeps the final-approval
step marked done for Active/Returned rentals (it read "awaiting review"
again after delivery), and the admin status badges colour Active/Returned as
approved states.

Refinements (operations-integrity batch):

- The "held for another rental" predicate lives in exactly one place,
  `RentalOperationService::isDeviceHeldForAnotherRental()`, used both when an
  owner return is **opened** (it is now refused at opening, so no task that
  can never run is left in the queue) and as the custody service's backstop.
- The allocation guard counts only an owner return that can still
  **execute** -- one whose rental's customer return is the device's latest
  movement. A superseded owner return (e.g. a legacy task left open while a
  later rental ran its course) no longer keeps the console off the market
  forever. A failed-but-retryable owner return still holds the device.
- `schedule()` refuses an assignee without a staff role
  (`config('rental.admin.roles')`), in the service, not only the form.
- The operations queue can be filtered by operation type.
- The reconciler also compares the rental lifecycle with the operations
  (`active_without_delivery`, `returned_without_return`,
  `lifecycle_behind_operation`). Still read-only.

### 14.5 Early return -- SUPERSEDED by §17 (now implemented, C-53)

Availability is decided by two predicates on `rental_reservations` and
nothing else: `scopeOverlapping()` (dates) and `scopeBlocking()` (state is
`paid` or `active`). Both the product-level check
(`RentalAvailabilityService::isFree()` / `constrainProductQuery()` /
`blockedRangesFor()`) and the device-level check
(`RentalOperationService::deviceOverlapsAnotherBlockingReservation()`) go
through them.

No operational step writes the reservation's `state`, so a returned rental's
reservation stays `paid` and keeps blocking until its `end_date` passes.
Releasing the remaining days after an early return would need ONE of:

1. a single writer that moves the reservation `paid -> active -> returned`
   (the transitions already exist in `ReservationState`) when the delivery
   and return complete, and `scopeBlocking()` then excluding `returned`; or
2. truncating the reservation's `end_date` to the return date.

Either changes customer-visible availability (the calendar and search) and
the device-level overlap check at once, and interacts with the undecided
product-capacity model (§10). It is therefore **not** implemented; it is a
policy decision, not a technical gap.

### 14.6 Still NOT decided

Damage amount and taxonomy, any charge or refund following an inspection,
anything that follows the window closing, whether the boundary instant counts
as inside the window, owner acceptance/signature wording for the return,
closure, reservation release after an early return, deposit, settlement.

## 15. Finance, damage and closure foundations

### 15.1 Confirmed rules implemented

| Rule | Where it lives |
|---|---|
| GamePek 35% / owner 65% (C-26/C-27) | `App\Support\Rental\SettlementSplit` -- integer Toman; shares always sum to the gross |
| The expert determines the damage amount (C-39) | `rental_damage_assessments`, entered manually against a **return** inspection by staff with `manage_operations` |
| Returned does not close by itself | `RentalClosureReadiness` reports; nothing closes |

### 15.2 Technical foundations (not decisions)

- **Settlement calculation.** `RentalSettlementService::calculate()` records
  one immutable `rental_settlements` row per returned owner rental
  (unique per reservation; CHECK that the shares add up; status is only ever
  `calculated`). It **refuses and audits `settlement.policy_undefined`
  while `rental.settlement.gross_basis` is null** -- the base amount is not
  decided. The only computable basis is `rental_total` (daily rate x days +
  extras - discount; excludes delivery fee and deposit). No money moves.
  `preview()` shows the would-be split read-only. Admin action requires
  `manage_rental_applications`.
- **Rounding** is a technical choice flagged for confirmation: GamePek's
  share is rounded down, the owner gets the remainder (at most 1 Toman).
- **Future wallet integration point:** credit `owner_share` with idempotency
  key `settlement:{reference_number}:owner` and the settlement's
  correlation id; `WalletService`'s unique index then makes a replayed payout
  run harmless.
- **Damage assessment** rows are append-only (revisions are new rows), with
  references derived from the inspection and cross-checked against its
  operation. No category, formula, responsibility or charge exists.
- **Closure readiness** (`RentalClosureReadiness`) reports each prerequisite
  as satisfied / missing / not applicable / policy undefined: customer
  return, return inspection, owner's 2-hour window, owner return, damage
  assessment, settlement calculation, deposit (B4), evidence retention
  (B11), closure trigger (B14). `ready` cannot be true while any policy item
  is undecided. Shown on the admin application screen for Returned rentals.
- **Visibility.** Admin sees readiness, the split (preview or calculated,
  both labelled unpaid) and damage amounts. The owner sees only their own
  calculated share, labelled "calculated -- not paid". Customers see none
  of it. Owners cannot withdraw anything.

### 15.3 Corrected assumptions

The customer quote box called the deposit "قابل استرداد" (refundable) and
admin/customer pages called it "بلوکه" (held). Neither is confirmed -- B4
(deposit hold/release) is open -- so the wording now says only what is true:
it is not collected at this stage.

### 15.4 Still NOT decided

Settlement gross basis, settlement trigger and daily-run mechanics, payout
destination, deposit amount/hold/release, refunds, whether damage is charged
and to whom, whether an assessment is required when nothing is damaged,
anything after the owner's window closes, closure trigger, tax/invoice rules,
GamePek-owned revenue treatment, early-return date release (§14.5).

## 16. Close-out: promissory note, damage payment, owner credit, closure

Supersedes the "not decided" parts of §15 for the rules confirmed as C-41–C-47.

| Step | Service | Record |
|---|---|---|
| Note received from customer | `GuaranteeNoteService::receive()` | `guarantee_note_events` (received) |
| Expert's damage amount (0 = no damage) | `RentalDamageAssessmentService::record()` | `rental_damage_assessments` (latest is current; frozen once paid or note resolved) |
| Customer pays assessed amount directly | `RentalDamageAssessmentService::recordPayment()` | `rental_damage_payments` (one per assessment, amount copied, external reference; no wallet movement) |
| Note back to customer (no damage / paid) | `GuaranteeNoteService::returnToCustomer()` | final event, basis recorded |
| Note to loss-bearing owner (unpaid) | `GuaranteeNoteService::transferToOwner()` | final event naming owner + device; owner devices only |
| Owner's 65% to Owner Wallet | `RentalSettlementService::finalize()` | `rental_settlement_credits` → one `wallet_transactions` credit, key `settlement:{ref}:owner` |
| Returned → Closed | `RentalChainOrchestrator::close()` | transition, only when `RentalClosureReadiness` is ready |

Closure prerequisites (all blocking): customer return, return inspection,
damage resolved (none / paid / note transferred), note returned or
transferred, owner's window over, device back with owner, owner credited.
GamePek-owned devices: window, owner return and settlement are not applicable.
Evidence retention (B11) is shown but does not block. There is still no
automatic closure: `closure_trigger` stays null and `transitionPostApproval()`
refuses Closed.

Invariants: return and transfer are exclusive (unique index + lock); every
identifier is derived from the application; damage payment is refused after
the note went to the owner. The reconciler reports settlement credits without
ledger evidence, amount mismatches, duplicate owner credits, ledger credits
without a settlement record, ineligible settlements, Closed rentals missing
prerequisites, contradictory note outcomes and damage-payment mismatches.

Aligned with C-48–C-52:

- Settlement stays a manual staff action; no scheduler exists (C-48).
- A damage payment credits the **GamePek system wallet**
  (`wallets.purpose = 'gamepek'`, held by no user) through
  `WalletService::creditGamePek()`, key `damage:{assessment_id}:gamepek`;
  `rental_damage_payments.wallet_transaction_id` names that entry. It never
  touches the owner's split (C-50).
- GamePek-owned device, unpaid damage: `GuaranteeNoteService::retainByGamePek()`
  records the final outcome `retained_by_gamepek`; closure no longer waits
  for an owner that does not exist (C-51).
- A cancelled rental leaves a received note **held**; every note outcome,
  damage payment and settlement requires a Returned rental, so cancellation
  produces none of them (C-52).
- New reconciler checks: damage payment without / mismatching its GamePek
  credit, duplicate damage credit, note transferred for GamePek stock,
  financial effect on a cancelled/rejected rental, settlement base ≠
  `rental_total`, deposit figure inside `payable_now`.
- Customer screens no longer show a "ودیعه" amount: the guarantee is shown
  as a physical promissory note with no cash deposit.

Still NOT decided: the final action for a note held on a cancelled rental;
whether a customer may still pay after the note was retained by GamePek
(currently refused, like after an owner transfer); the meaning of the
product's legacy `deposit` figure (still stored as
`reservation.deposit_amount`, shown to staff only, and fed into the
contract template's `deposit_amount` placeholder).

## 17. Physical-device capacity, early return, no mid-rental reclaim

Implements C-53–C-56.

**Old assumption removed.** `RentalAvailabilityService::isFree()`,
`constrainProductQuery()` and `blockedRangesFor()` treated any overlapping
blocking reservation as blocking the whole product (one unit per product);
`recordSelection()` and `materialiseAfterPayment()` relied on that.

**Model now.** Eligible devices = the product's approved devices
(`Device::rentable`). A range is available when, after adding it, every
blocking reservation of the product can still receive its own device,
respecting devices already attached (`App\Support\Rental\DeviceAssignmentFeasibility`,
pure, backtracking, fail-closed on its node budget). Zero eligible devices =
never available. A reservation blocks from `start_date` to
`LEAST(end_date, returned_on)`.

**Why feasibility, not a count.** Reservations are paid before a device is
attached and attachment is manual; a plain count could admit bookings that a
later manual choice strands. The same check therefore also runs in
`attachDevice()`, which now locks the product row (serialising with
payments) and refuses a choice that would leave another paid booking with no
possible device. It never selects a device.

**Early return.** `rental_reservations.returned_on` is written only when the
`customer_return` handover completes (RentalOperationService, same
transaction). The contractual dates, price, settlement and close-out are
untouched; the return day still blocks, the following days are free.

**No mid-rental reclaim.** `DeviceRegistrationService::disable()` (audited,
re-checked under the device lock) and `DevicePolicy::disable` refuse while
the device is with a customer or attached to a paid reservation whose rental
has not returned. The only way home remains the post-return owner-return leg.

**Reconciler:** device double-booked, capacity exceeded, reservation on a
device of another product, reclaim during a rental, `returned_on` disagreeing
with the return handover.

**Customer UI:** search lists a product only when a unit is free; the
calendar marks only days with no free unit; a refused booking shows the
existing Persian conflict message. No waitlist is mentioned.

Still NOT decided: what happens when all devices are busy (C-56); whether a
disabled device's future unassigned bookings are re-planned; device selection
policy (still manual). Late return is now decided and implemented -- §18.

## 18. Late return, and the retained note -- IMPLEMENTED

### 18.1 Availability (C-57)

The mirror image of the early return in §17. A device that has not come back by
the contractual end date is **not released on that date**: while the rental is
still `Active` past its `end_date`, `RentalReservation::blockedUntil()` answers
`OPEN_ENDED` (`9999-12-31`), so every availability question -- search, the
product calendar, `isFree()`, device attachment -- treats the device as taken
with no known free date. When the physical return completes, the same
`returned_on` the early-return path writes (from the `customer_to_gamepek`
handover, inside its transaction) becomes the block's last day: the return day
itself stays occupied and the device is offered again **from the next day**.
The days between the contractual end and the actual return stay blocked too --
the device really was out on them.

`scopeOverlapping` carries the same rule in SQL (`BLOCKED_UNTIL_SQL`), so the
query and the PHP answer cannot drift.

The contractual `start_date` and `end_date` are **never** rewritten by
lateness, and a late rental neither closes nor settles itself.

One deliberate exception keeps legacy rows safe: the open-ended block applies
only while the application is `Active`. A rental that is `Returned` or `Closed`
but has no `returned_on` (a row from before that column existed) falls back to
its contractual end date and does not block its device forever.

### 18.2 The late charge (C-57)

`App\Support\Rental\LateReturn` -- pure, read-only, no writes anywhere:

```
late days = actual return date − contractual end date   (whole days, min 0)
base      = late days × rental_reservations.daily_rate
surcharge = 15% of base, rounded DOWN to the Toman
total     = base + surcharge
```

Returned on the end date = 0 late days; the next day = 1. While the device is
still out the count runs against today and is marked `stillOut`, i.e. not
final. The daily cost is the reservation's own snapshotted `daily_rate`, so no
new price model was invented; the extra-controller fee and the duration
discount are excluded, because whether they extend into a late period is not
decided.

**Nothing is charged.** No ledger entry, no settlement effect, no addition to
`rental_total`, no automatic closure. Who receives the late fee -- owner,
GamePek, or a split -- is undecided, so a settlement on a late rental records
`settlement.late_fee_undistributed` in the audit trail and settles the rental
price exactly as it would for an on-time return.

### 18.3 A retained note is not terminal (C-58)

A GamePek-owned device has no owner to hand the note to, so an unpaid damage
leaves the note **with GamePek**. GamePek still physically holds it, so:

- `retained_by_gamepek` no longer claims `final_marker`; that marker exists
  only to make "returned to the customer" and "handed to the owner" mutually
  exclusive at the database level, and retention is neither;
- a damage payment is refused only once the note has **left** GamePek
  (returned or transferred) -- retention does not block it;
- after such a payment the note goes back to the customer with basis
  `damage_paid`, exactly as a straight payment would;
- retained-then-transferred-to-an-owner is refused: one physical document
  cannot have two destinations;
- re-pricing still stops at retention, so the amount the customer can pay is
  the amount that was recorded.

### 18.4 Paying after closure (C-59)

CONFIRMED: closing a rental does not extinguish an unpaid damage. A rental can
be closed **because** the retained note resolved the obligation for closure
purposes, so the customer must still be able to settle it afterwards:

- `recordPayment()` accepts `Returned` **or** `Closed`; every other state is
  still refused, cancelled and rejected included (C-52 gives them no rule);
- `returnToCustomer()` accepts `Closed` as well, so the paid note goes home
  through the same append-only event mechanism;
- transfer and retention stay `Returned`-only — no confirmed rule creates
  either after closure.

A post-close payment is a **financial record and nothing else**. It writes one
`rental_damage_payments` row and one GamePek wallet credit, both idempotent.
It does not: move `rental_applications.state` (the orchestrator is still its
only writer, and `nextState()` now short-circuits terminal states so a closed
rental cannot be re-derived), open an operation, move custody, re-block the
device, or create or recalculate a settlement. Closure readiness cannot regress
either: paying an outstanding damage only moves `damage_resolution` from
satisfied-by-retention to satisfied-by-payment.

**Late fee, restated (C-60):** the recipient is DEFERRED by owner decision.
Nothing charges, credits, splits or offers to settle it; admin shows it as a
calculation explicitly distinguished from payable money.

**Reconciler additions:** a device released while it is still in the customer's
custody; a legacy return with no release date (classified apart from a real
disagreement, and no date invented for it); damage money taken after the note
went to the owner; any wallet entry claiming to be a late fee, which no
confirmed rule authorises.

## 22. Pre-launch audit: findings closed and deployment prerequisites

### 22.1 Closed in the pre-launch audit

- **Identity media and the framework file route.** The `local` disk had
  `serve => true`, which registers a public `storage/{path}` route over
  `storage/app/private` -- the root the `verification` disk (national-ID images,
  selfies) lives inside. The installed `ServeFile` does demand a signature, so
  nothing was exposed; but a signature is a bearer token (VID-04), and this route
  checks no ownership and audits nothing. Nothing used it. It is off; identity
  media is served only by `verification.media.show`.
- **Bank data in logs.** Pardakht Novin request/confirm/reverse logged the full
  response body and the session token at INFO/ERROR. Logs now carry the HTTP
  status, the gateway's status code and presence flags only.
- **Signature evidence.** `contract_signatures` had no immutability guard. Any
  change to the evidence columns, and any delete, now throws; `verified_at`, the
  one column the flow fills later, still can be set.
- **Callback lookup.** Every public payment callback runs
  `WHERE gateway = ? AND authority = ?` and only `gateway` was indexed. A
  composite index now serves it (not unique: an existing duplicate would fail
  the migration mid-deploy).

### 22.2 Deployment prerequisites (manual; not automated here)

`deploy.sh` is forward-only and safe: maintenance mode, `composer install
--no-dev`, `migrate --force`, `optimize`, `storage:link`. It never runs
`key:generate`, a seeder, `migrate:fresh`, `db:wipe` or `refresh`. Before the
first production run, and on every environment:

1. **Own database.** `DB_DATABASE` must name the Rental database, never the
   Store's. The two applications share nothing.
2. **`APP_ENV=production`, `APP_DEBUG=false`.** `.env.example` ships the local
   values. Production mode is what disables the mock gateway, the fake
   providers, the `log` SMS driver, OTP display and model strict-mode leniency.
3. **`APP_KEY` generated once and never regenerated.** It keys the OTP HMAC,
   the contract signature seal and encrypted columns (sayad id). Regenerating it
   on an existing install breaks every sealed signature and encrypted value.
   `deploy.sh` never touches it; do not add it.
4. **`SESSION_SECURE_COOKIE=true`** behind HTTPS.
5. **Seeding** is blocked in production unless `ALLOW_PRODUCTION_SEED=true`;
   set it only for the first seed, then remove it. The seeded super-admin is
   OTP-only (`ADMIN_MOBILE`); the password dev-admin exists only in
   local/testing.
6. **Payment:** leave `PAYMENT_GATEWAY` pointing at a configured gateway. With
   no credentials every payment attempt refuses before any network call.
7. **Backups** of the Rental database before each `migrate --force`: the
   migrations are additive, but no rollback plan replaces a backup.

## 21. Payment and SMS integration boundary

### 21.1 Payment -- what was already right

The flow was sound before this batch and was not rebuilt: an order is marked
paid ONLY on the gateway's server-to-server `verify()`, under a row lock, with
an amount cross-check; callback query parameters locate the transaction and
decide nothing; replays return early; the reservation is materialised after the
payment commits, under a product lock, idempotently; mock is structurally
unreachable outside local/testing; the payment gate (KYC + bank) is server-side.

### 21.2 Payment -- gaps closed

- **Callback logging.** The callback's raw parameters were logged at INFO. A
  real gateway callback can carry a masked card number, a bank reference or a
  session token. Only the gateway, whether an authority arrived, and the field
  NAMES are logged now.
- **Missing credentials.** With no Corporation PIN, the Pardakht Novin adapter
  POSTed to the live bank endpoint with a null PIN. It now refuses before any
  network call (request, refund) and answers `Unknown` on verify, so a payment
  taken before the configuration was lost stays pending and settleable.
- **A dropped Confirm call.** A timeout or transport error was reported as
  "verified, not paid", which marked the transaction failed -- although the bank
  may already have taken the money, and a failed row can never be settled
  again. It is now `Unknown`: the row stays pending for reconciliation, which is
  exactly the meaning PaymentService gives that state. A genuine decline (a
  non-null status code) is still a failed payment.

### 21.3 SMS

`config('rental.sms.state_templates')` was declared but nothing dispatched it.
`App\Services\Notification\RentalLifecycleNotifier` now does, hooked on the one
record every committed state change writes (a `rental_application_transitions`
row), so the orchestrator stays the sole state writer and knows nothing of SMS.

- sent **after commit**: a rolled-back change never announces itself;
- **isolated**: any sender failure is caught, recorded (exception class only --
  a message can carry a URL or key) and swallowed; the rental, custody,
  reservation, wallet and settlement are untouched;
- **once**: keyed `transition:{id}` with a unique index on
  `sms_messages.dedupe_key`; retrying a failed message re-dispatches its row;
- **silent** while the map is empty -- which it is, because no customer copy is
  approved (B13). No Persian message text was written in this batch.

The `log` driver was the default in every non-production environment and
reported messages as DELIVERED. It is now the default only in local/testing, is
refused anywhere else even if configured, masks the mobile in its log line, and
answers `unknown` to a delivery check. `SmsService::dispatch()` previously
caught only `ProviderException`; anything else left the row stuck in `sending`,
invisible to the retry sweep. It is now marked failed.

### 21.4 Ready but blocked

- **Real payment provider:** the adapter implements Pardakht Novin's documented
  NormalSale / Confirm / Reverse. Blocked on merchant credentials, and on an
  inquiry operation (the doc has none, so `status()` stays `Unknown`) and a
  callback field table (the doc gives none; `Token` is inferred from the
  consistent naming of every documented operation).
- **SMS provider:** no provider chosen, so no adapter exists. The boundary
  (`SmsSenderInterface`, `sms_messages`, retry sweep, delivery sync, dedupe) is
  ready for one.
- **SMS copy:** every template and the state map stay empty until the owner
  approves customer-facing text (B13).
- **Damage payment** stays a staff-recorded external payment; no customer
  gateway flow was built.

## 20. The lifecycle rungs verify their own evidence

`RentalChainOrchestrator::transitionPostApproval()` used to move a rental on
config trigger plus state adjacency alone. The confirmed rules -- Active only
after the delivery, Returned only after the customer return -- lived entirely in
the CALLER: `RentalOperationService` asks for the move only after a handover it
has just completed. Correct, but discipline rather than a guard, and a console
command, an import or a future screen could have produced an Active rental with
no delivery behind it. The reconciler would then report
`active_without_delivery` after the fact.

The mover now checks for itself, under the same lock and inside the same
transaction: a COMPLETED operation of the matching type carrying a handover
whose possession actually moved. The legitimate path is unaffected (the
operation and its transfer are written first), the denial is audited as
`rental_application.transition_denied` BEFORE the transaction so it survives the
throw, and the check is repeated under the lock. No policy was invented -- a
confirmed precondition is simply enforced where the state is written.

## 19. The admin rental dashboard -- IMPLEMENTED

`admin/rental-dashboard` (`Admin\RentalDashboardController` +
`App\Services\Rental\RentalDashboardMetrics`), gated on
`view_rental_applications`. It is the rental counterpart of the shop's
`admin.dashboard`, not a replacement for it.

READ-ONLY, and tested to be: opening it derives no state, completes no
operation, moves no custody and writes nothing. It offers no actions of its
own -- every action stays on the screen that owns it, with its own permission
check.

What it shows, all from real aggregates: where every application stands and the
final-approval queue; open operations by type, including the
`awaiting_device_allocation` queue no code may clear; what each Returned rental
still needs before it can close; damage assessed vs damage received; where the
physical notes are; settlement calculated vs credited; fleet size, units out
with customers and products with no device at all; overdue rentals; reconciler
findings by code; the last completed movements.

TWO RULES IT FOLLOWS.

1. **Money is labelled by what it is.** `assessed`, `calculated` and `credited`
   are never summed into one figure. An assessed damage is not a receivable --
   there is no payment deadline -- and a calculated settlement is not a payment.
2. **A deferred policy is never given a number.** The late-return section
   carries no amount at all: the fee's recipient is deferred (C-60), so the
   dashboard reports the overdue QUEUE and says in plain Persian that the fee
   is calculated only, enters no settlement and has no "settle" action.

QUERY BUDGET. Every section is a fixed set of aggregates plus at most one
bounded (10-row) eager-loaded list; a test doubles the data and asserts the
query count does not move. The single exception is the integrity section, which
delegates to the reconciler -- a record-by-record diagnostic whose cost grows
with the data by design. It is not reimplemented (one definition of a
contradiction), and `snapshot(withIntegrity: false)` exists to measure or
render without it.

## 12. Delivery / customer custody feasibility analysis -- superseded by §13

A task requested adding the `gamepek -> customer`, `customer -> gamepek` and
`gamepek -> owner` custody legs, a delivery `RentalOperation` type, and a
customer return operation type, on the premise that this could be done as
"structural scaffolding" without deciding policy. **It cannot, and the
project's own code already says so:**

- `App\Enums\RentalOperationType`'s docblock, verbatim: *"Delivery, customer
  return, owner return and inspection are real future operations, and they
  are deliberately ABSENT rather than declared-and-disabled: an enum case
  that cannot be produced is a promise the code does not keep, and the audit
  found that pattern in this project already."* Adding an inert
  `CustomerDelivery`/`CustomerReturn` case is exactly the pattern this
  comment names and rejects, not new scaffolding.
- `App\Enums\CustodyActor`'s docblock, verbatim: *"Customer is declared
  because custody genuinely has three parties and a two-valued column would
  have to be widened later under live data. No customer transfer is
  executable in this phase -- see CustodyTransferType."* The actor
  *vocabulary* was deliberately future-proofed already (`CustodyActor::Customer`
  exists today); the project stopped precisely at the line between "an actor
  can be named" and "a transfer type makes that name executable," and that
  line is exactly where a policy decision is required.
- The `device_custody_actor_pair_ck` CHECK constraint (`database/migrations/
  2026_09_11_000002_add_custody_integrity_constraints.php`) only constrains
  rows where `transfer_type = 'owner_to_gamepek'`; for any other type value
  it is vacuously satisfied. Adding a new `CustodyTransferType` case safely
  -- consistent with this codebase's own "the service being the only writer
  is a fact about today's code, not an invariant" philosophy -- would require
  a new constraint clause encoding the correct actor pair for that leg. That
  is not a structural decision; it is an answer to "may GamePek release a
  device to a customer, and under what evidence," which is exactly:
  - `CONFIRMED_DECISIONS.md` §4.1: *"Releasing a device from GamePek custody
    without a completed rental — no such transition exists"* (undecided
    business policy, listed among items requiring an owner decision);
  - `CONFIRMED_DECISIONS.md` §4.2: whether a custody handover needs a
    receipt or signature (open legal gate, and it applies to any handover
    leg, not only the one that exists today).
- Device allocation itself is a further precondition (`reservation.device_id`
  is populated only by a human's manual `attachDevice()` call, per the
  device-allocation audit) -- a delivery/return leg would depend on it being
  reliably true for a given reservation, and the allocation *policy* (which
  device, chosen by whom) remains undecided (§10 above).

**Conclusion:** every downstream item in that task (delivery/return
operation types, their services, admin screens, customer authorization,
customer-facing status, customer custody actions, reconciler coverage) is
gated on the same undecided business/legal questions this domain has
consistently refused to guess at elsewhere. Nothing was added. This
paragraph is the record of that determination, so it does not need
re-deriving next time the question comes up.
