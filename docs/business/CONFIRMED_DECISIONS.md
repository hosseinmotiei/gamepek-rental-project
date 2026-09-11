# GamePek Rental — confirmed business decisions

<div dir="rtl">

> این سند تصمیم‌های **تأییدشدهٔ کسب‌وکار** را ثبت می‌کند. نام کد، فیلد و
> اصطلاح فنی عمداً انگلیسی مانده تا قابل جستجو باشد.
>
> ثبت‌شده: ۱۷ شهریور ۱۴۰۵ (2026-09-08)

</div>

**Scope of this document:** it records policy that has been *decided*. It does
**not** describe what the code does today, and nothing here has been
implemented as part of recording it.

Where a confirmed decision differs from current behaviour, that is called out
explicitly in §3 — those are **implementation gaps**, not new decisions.

Anything not listed in §1 is either in §4 (policy gate) or is not decided.
**Do not infer a rule from silence.**

---

## 1. Confirmed decisions

### 1.1 Geography and timing

| # | Decision |
|---|---|
| C-01 | Service area is **Tehran only** for now |
| C-02 | Within Tehran, **zones 1 and 2 only** |
| C-03 | Rental is **date-based, not hourly** |
| C-04 | Minimum rental duration is **1 day** |
| C-05 | Maximum rental duration is **unlimited** |
| C-06 | **Future booking is supported** |

### 1.2 Customer journey ordering

| # | Decision |
|---|---|
| C-07 | A customer **may choose a device before KYC** |
| C-08 | **KYC must be completed before payment** |
| C-09 | **Reservation occurs only after a successful, verified payment** |
| C-10 | **There is no unpaid reservation hold** |
| C-11 | Abandoned applications **remain stored but do not block inventory** |

> C-09, C-10 and C-11 together mean inventory is only ever committed by money
> that has actually cleared. See §3 — current code does not yet work this way.

### 1.3 Devices and ownership

| # | Decision |
|---|---|
| C-12 | One **Product** can have **multiple physical Device Units** |
| C-13 | Every **Device Unit has its own serial number** |
| C-14 | An **Owner can have multiple devices** |
| C-15 | **GamePek can also own devices** (mixed fleet: GamePek-owned and owner-supplied) |
| C-16 | The **Owner chooses availability dates** |
| C-17 | A device becomes available again **according to the approved availability / operational state** |

> C-12 answers the previously open "one Product = one unit or a pool?" question:
> **a pool of individually-identified units.** This changes the availability
> model — see §3.

### 1.4 Pricing

| # | Decision |
|---|---|
| C-18 | **GamePek determines rental pricing** |
| C-19 | The **Owner cannot directly change price** |
| C-20 | The Owner **may request** a price change |
| C-21 | **Multi-day discounts exist** |
| C-22 | **Admin controls** the discount duration/range and the percentage |
| C-23 | The **selected game can affect price** |

### 1.5 Concurrency

| # | Decision |
|---|---|
| C-24 | **Multiple concurrent customer rentals are allowed** |
| C-25 | There is currently **no concurrent-rental limit** |

### 1.6 Money

| # | Decision |
|---|---|
| C-26 | **GamePek commission = 35%** |
| C-27 | **Owner share = 65%** |
| C-28 | **Owner settlement is daily** |
| C-29 | Rental financial movement **uses the Wallet** |

### 1.7 Notifications

| # | Decision |
|---|---|
| C-30 | **All rental lifecycle events should support SMS** |

> The *copy* for each message is not approved — see §4.

### 1.8 Delivery, custody and return

| # | Decision |
|---|---|
| C-31 | A rental becomes **Active only when GamePek physically delivers the device to the customer** — not when the start date arrives |
| C-32 | **GamePek performs the delivery** |
| C-33 | Delivery requires a **receipt/acceptance and a customer signature** |
| C-34 | The device is **checked and diagnosed at the customer's door** during delivery |
| C-35 | A customer **refusing delivery incurs a penalty** — the amount/calculation is **NOT decided** |
| C-36 | A **failed delivery is handled case by case** with the customer — no automatic consequence |
| C-37 | The **customer returns the device to GamePek**, coordinated **through support** |
| C-38 | The **owner has 2 hours after GamePek receives the device** to report a defect. After that GamePek carries no responsibility for that defect; disputes go through the contracts, receipts and evidence GamePek holds |
| C-39 | **GamePek's expert determines the damage amount** when damage exists — no formula, taxonomy or pricing is defined |
| C-40 | After the customer returns it, the device **eventually goes back from GamePek to the owner** |
| C-41 | Settlement is calculated **only from the rental price**; the delivery fee and the promissory note are excluded |
| C-42 | GamePek's 35% is **rounded down** to the Toman; the owner receives the exact remainder |
| C-43 | The owner's share goes to the **Owner Wallet**, after: customer return, return inspection, the owner's 2-hour window, and the device's return to the owner. Calculated is **not** paid |
| C-44 | GamePek collects **no cash deposit**; the customer gives a **promissory note** as guarantee. It is never wallet money and never part of settlement |
| C-45 | No damage → the note is **returned to the customer** |
| C-46 | Damage → the customer may **pay the assessed amount directly**; if paid, the note is returned |
| C-47 | Damage not paid → the note is **handed to the loss-bearing owner**, who pursues it through the competent authorities. GamePek does not collect or prosecute it |
| C-48 | Settlement is **manual** for now (staff finalization); no scheduled or daily job |
| C-49 | The customer pays the **full rental amount** before the reservation exists; there is **no cash deposit** |
| C-50 | A paid damage goes **in full to the GamePek Wallet**; it is not split 35/65 and does not change the owner's settlement |
| C-51 | GamePek-owned device with unpaid damage → there is no owner; the note **stays with GamePek** (no legal workflow) |
| C-52 | Rental cancelled after the note was received → the note **stays held** by GamePek; no automatic return or transfer until a policy is defined |
| C-53 | **Early return**: once the customer return is recorded, the device is free for the remaining unused days; no refund or price recalculation follows |
| C-54 | An owner may **not** take a device back before its rental ends; no owner-withdrawal workflow exists |
| C-55 | Availability is **physical-device capacity**: a product with N eligible devices can serve N overlapping rentals; one device never has two overlapping blocking reservations |
| C-56 | When every eligible device is busy, the booking is simply refused — waitlists, queues, backorders and fallbacks are **undecided** |

> **Implemented** (see `docs/operations/OPERATIONS_AND_CUSTODY.md` §13–§14):
> C-31, C-32, C-34, C-37, C-40 and the C-38 window's arithmetic, i.e. all four
> custody legs (`owner_to_gamepek`, `gamepek_to_customer`,
> `customer_to_gamepek`, `gamepek_to_owner`). C-33's receipt handle is
> recorded; whether a digital signature may replace the paper one is not
> decided. C-39 is supported only as **evidence**: staff can append free-text
> inspection findings to a delivery or a return; no amount is recorded.
>
> **How C-38 is read in code** (technical interpretation of the confirmed
> rule, not a new decision): the window starts at `transferred_at` of the
> `customer_to_gamepek` handover — the moment GamePek recorded receiving the
> device back from the customer — and is half-open, so the exact two-hour
> instant is already outside it. If the owner means the boundary instant to
> count as inside, that is a one-line change; it has **not** been confirmed.
>
> **Deliberately not implemented**, because the rule stops short of a
> computable one: C-35's penalty amount, C-36's consequences, C-39's damage
> amount, and anything financial that follows C-38's window closing. No rule
> links the owner return to the window, so an owner return is not gated on it.

---

## 2. What this changes about previously open questions

Two questions that blocked architecture are now answered:

| Previously open | Now confirmed |
|---|---|
| One `Product` = one physical device, or a pool? | **Pool.** One Product, many Device Units, each with a serial number (C-12, C-13) |
| GamePek-owned fleet vs third-party owners? | **Both.** Owners supply devices *and* GamePek owns devices (C-14, C-15) |

The Owner and Device Unit domains can therefore be designed. They are still not
built — see §3.

---

## 3. Confirmed decisions the current code does not yet implement

Recorded so the divergence is visible. **No behaviour was changed to record
this.**

| # | Confirmed | Current code |
|---|---|---|
| C-08 | KYC before payment | **No gating.** A customer can reserve and pay with no identity record at all |
| C-09 | Reservation only after verified payment | **Reversed.** The chain is `ReservationHeld → PaymentPending → Paid` — the reservation is created first |
| C-10 | No unpaid hold | **Unpaid holds exist.** `RentalReservation` is created in state `held` before any payment |
| C-11 | Abandoned applications must not block inventory | **They do block.** Reservations are never released; a rejected or abandoned application blocks its dates permanently |
| C-12 / C-13 | Product → many serialised Device Units | **Implemented.** `devices` holds one row per physical console with a unique normalised serial; one product has many. `Device` IS the unit — no separate `device_units` table. Reservations still allocate at product level: `rental_reservations.device_id` exists but is always NULL, because the allocation rule is undecided |
| C-14 / C-15 | Owner domain, mixed fleet | **Implemented.** `owners` (1:1 with `users`) plus `devices.ownership` = `gamepek` \| `owner`, bound by a CHECK constraint. GamePek stock needs no owner account. Owner registration, admin review (approve/reject) and ownership isolation are in place |
| C-16 | Owner-chosen availability | **Not implemented, and blocked.** There is no owner availability table, model, service, route or screen. Availability is derived from `rental_reservations` alone. Building the calendar needs four undecided answers — see §4.1 |
| C-03 / C-04 | Date-based, minimum one day | **Implemented and now pinned.** Ranges are inclusive on both ends (`end = start + days - 1`), so a one-day rental occupies only its start date. The search form used to reject `from == to`, making the shortest rental GamePek sells unsearchable, and reported every window one day short |
| C-05 | Maximum duration unlimited | **Diverges.** `RentalApplicationController::reserve()` validates `days` as `max:365`. The availability authority imposes no maximum. Whether the 365-day cap is a real rule or leftover scaffolding is unconfirmed — it was left in place rather than removed on a guess |
| C-17 | Device available again per operational state | **Partially implemented.** The operational domain now exists for one step only: a paid reservation opens an `owner_device_pickup` task in `rental_operations`, and `device_custody_transfers` records who physically holds the device. Inspection, delivery, customer return and owner return are NOT implemented, so a device never becomes available again through an operational path |
| C-21 / C-22 | Admin-controlled multi-day discounts | Duration discount tiers exist in `config('rental.pricing.duration_discounts')` but are a **hardcoded placeholder**, not admin-editable and not owner-approved values |
| C-23 | Selected game affects price | `RentalPricingService::quote()` accepts a `gameFee` parameter, but it is **always passed 0**; there is no game selection |
| C-26 / C-27 / C-28, C-41–C-43 | 35/65 split to the Owner Wallet | **Implemented.** Base = `reservation.rental_total` (rental price; excludes delivery fee and the note). `RentalSettlementService::finalize()` credits the owner once through `WalletService` after the C-43 settlement point; `rental_settlement_credits` separates credited from calculated. **Not implemented:** a scheduled *daily* run — finalization is a staff action per rental |
| C-44 – C-47 | Promissory note lifecycle | **Implemented** as `guarantee_note_events` (received → returned to customer XOR transferred to owner), driven by the damage outcome. No legal wording, deadline or collection workflow |
| C-29 | Wallet carries financial movement | **Backend now implemented** (`App\Services\Wallet\WalletService`, `wallets`, `wallet_transactions`) — persisted balance, immutable ledger, idempotent credit/debit. **Nothing calls it yet**: no settlement, payout, deposit, refund or damage-charge logic exists or is invented by it. The customer profile's wallet tab is still a separate, unconnected `localStorage` prototype |
| C-30 | SMS on all lifecycle events | **No SMS is ever sent.** Templates are empty; the seam exists |
| C-01 / C-02 | Tehran, zones 1–2 | `config('rental.search.cities')` is `['تهران']` — city is enforced, **zones are not modelled at all** |

---

## 4. Policy gates — NOT decided, must not be invented

These remain **blocked**. Code must continue to fail closed and record
`*.policy_undefined` rather than guess. Nothing below may be implemented until
the owner decides it, and several additionally require legal review.

### 4.1 Requires business owner decision

- Cancellation rules (customer, owner, GamePek)
- Refund percentages and rules
- Owner cancellation penalty
- GamePek cancellation compensation
- Guarantee type and amount
- Deposit policy
- Guarantee enforcement
- Dispute policy
- Exact damage valuation methodology
- Lost-device valuation
- Owner device-disable penalty amount / formula — an owner **can** disable a
  device today (`DeviceState::Disabled`, recorded and audited) and **nothing is
  charged, deducted or escalated**, because the penalty is undefined
- Whether owner verification must COMPLETE before that owner may register a
  device — registration is currently permitted from `pending_verification`,
  since nothing goes live on it: every device still needs admin approval
- Which free device a paid reservation is allocated (prefer GamePek stock,
  rotate for owner fairness, favour condition?) — interacts with the 35/65
  split and daily settlement. The operational task now makes this gate
  **visible instead of silent**: a pickup sits in `awaiting_device_allocation`
  until a human names a device, and no code path chooses one
- What follows a **failed pickup**. Today the failure is recorded, audited and
  shown to admin, and nothing else happens. Refund, owner penalty, replacement
  device, reservation cancellation and owner suspension are all undecided
- Whether a device already in GamePek custody may be released or re-picked-up
  without a completed rental. Delivery to the customer (C-31/C-32) is now a
  defined release path; releasing it for any OTHER reason still has no
  transition and remains undecided
- Device condition taxonomy — `devices.condition` is free text, no grades invented
- **The owner availability calendar (C-16) cannot be built until these are
  decided.** C-16 confirms owners choose their dates, but not what that means:
  1. **Default.** With no availability record, is a device available always or
     never? Opposite answers, both defensible. One takes the whole fleet off
     the market; the other makes owner calendars decorative
  2. **How device availability reaches the customer.** Customers book a
     PRODUCT, because device allocation is undecided. Owner availability is
     per DEVICE. Connecting them means defining "product available" in terms
     of individual devices, which is the allocation question wearing a hat
  3. **GamePek-owned stock.** `owner_id` is NULL and there is no owner to
     choose dates. Does first-party stock have a calendar, and who edits it?
  4. **Is availability a promise?** If an owner marks dates available and then
     withdraws them, is anything owed? That reaches the undefined owner
     penalty above
  Until these are answered, availability stays reservation-derived. Nothing in
  the code guesses at any of them
- Courier / delivery provider

### 4.2 Requires legal review

- Exact legal effect of the **two-hour issue-report window**
- Whether a **custody handover needs a receipt or a signature**. PARTLY
  DECIDED: C-33 confirms a **delivery to the customer** requires a receipt and
  a customer signature, taken at the door. What is still open is (a) the same
  question for the **other** legs — the owner pickup and the eventual return
  to the owner — and (b) whether a **digital** signature may replace the
  physical one. The in-app confirmation (`custody.acknowledged`, available to
  both the owner and the customer) is expressly **not** a signature, not legal
  acceptance, and not a statement about the condition of the device. The
  `reference_number` (`CUS-…`) names the physical receipt; nothing legal may
  be built on the in-app confirmation until this gate is closed
- Final contract text
- Cheque / promissory-note legal terms
- Electronic-signature legal validity
- Minor / guardian legal details
- Data and video retention periods
- Biometric consent details
- Tax and invoicing requirements

### 4.3 Still-open items from the existing `TODO(business)` register

The B-register in the code remains authoritative for these. Confirmed decisions
above resolve none of them except where §2 states otherwise:

B1 required KYC checks · B2 liveness/face thresholds · B3 attempt cap ·
B4 deposit hold/release · B5 guarantee amount formula · B6 mandatory cheque
inquiries · B7 risk thresholds · B8 final-approval criteria · B9 cancellation/
refund · B10 reservation hold expiry · B11 media retention · B12 contract text ·
B13 SMS copy · B14 post-approval triggers.

> **B10 note:** C-09/C-10 remove the *need* for an unpaid hold timeout, since no
> unpaid hold should exist. B10 is not thereby "decided" — it is superseded once
> C-09/C-10 are implemented.

---

## 5. Rules for using this document

1. **Do not implement anything here as a side effect of reading it.** §3 is a
   gap list, not a work order.
2. **Do not treat §4 as soft.** An undefined policy means the code refuses and
   audits. That is the designed behaviour, not a bug to work around.
3. **Do not infer.** If a rule is not in §1, it is not decided.
4. When a §4 item is decided, move it into §1 with a `C-nn` number and record
   the date.
