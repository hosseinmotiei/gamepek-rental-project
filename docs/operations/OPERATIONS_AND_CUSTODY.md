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

- Physical inspection, condition grading, inspection approval or rejection
- Delivery, courier, customer acceptance, transition to an active rental
- Customer return, owner return, damage, late return, lost device
- Guarantee, deposit, refund, cancellation, settlement, wallet
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

## 12. Delivery / customer custody feasibility analysis -- not implemented, and why

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
