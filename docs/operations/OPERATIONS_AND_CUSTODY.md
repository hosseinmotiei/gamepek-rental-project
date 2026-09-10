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

## 6. Concurrency and idempotency

- `unique(rental_reservation_id, type)` on `rental_operations` — one pickup per
  reservation, forever.
- `unique(rental_operation_id)` on `device_custody_transfers` — one handover per
  task.
- Every transition locks its row with `lockForUpdate()` first. Two operators
  pressing "received" at once produce exactly one custody record; the loser gets
  a safe Persian conflict message.

---

## 7. Audit

Actions recorded through the existing `AuditLogger`: `operation.created`,
`operation.awaiting_device_allocation`, `operation.device_attached`,
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
- Receipts, documents or signatures for a handover

## 10. Open gates relevant to this domain

- **Device allocation rule** — undecided; nothing chooses a device
- **What follows a failed pickup** — undecided; the failure is recorded and
  nothing else happens
- **Receipt / signature requirements for a handover** — open legal gate;
  `acknowledged` claims no legal effect
- **Releasing a device from GamePek custody without a completed rental** — no
  such transition exists

See `docs/business/CONFIRMED_DECISIONS.md` §4 for the full register.
