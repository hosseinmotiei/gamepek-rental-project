# GamePek Rental — Complete Project Audit

**Audit date:** 2026-09-08
**Commit audited:** `554befa` (branch `main`, working tree clean apart from an
uncommitted `CLAUDE.md` from a prior documentation task)
**Auditor mode:** read-only. No source file, migration, schema or UI was
modified. No commit created.

## How this audit was conducted

Findings are marked **[VERIFIED]** when I executed something and observed the
result, **[CODE]** when read from source without runtime proof, and
**[UNKNOWN]** when it could not be determined.

What I ran:

- Installed dependencies, created `.env` (DB = `gamepek_rental`), booted the
  app on `http://127.0.0.1:8000`
- Executed the full test suite against `gamepek_rental_test`
- Drove a live customer journey with real HTTP: OTP login → application →
  reservation → payment → mock gateway → identity submission
- Drove a live admin journey: login → rental applications → rejection
- Ran a 5-way concurrency test against one device and date range
- Enumerated all 185 routes from the running application
- Analysed rendered HTML from 9 public pages for SEO, accessibility and markup

**Limitation, stated plainly:** no browser automation or screenshot tooling is
available in this environment. I could not render pages, measure real layout,
inspect computed styles, test touch targets, or observe visual regressions at
breakpoints. Every visual/UX score below is therefore derived from markup and
CSS class analysis, not from pixels. Where a judgement genuinely requires
rendering, it is marked **[UNKNOWN — REQUIRES VISUAL QA]** rather than guessed.
This is the single largest gap in this audit.

---

# 1. Executive Summary

## 1.1 What GamePek Rental actually is today

A **Laravel 11 / PHP 8.2 customer-side rental application platform** with an
unusually rigorous state machine, a genuine audit trail, and a complete set of
fail-closed integration seams — attached to a **catalog the business does not
model correctly** and **missing the entire operational half of the business**.

The customer can, today, really do this end to end: search by city and Jalali
date range → see genuinely available devices → reserve → pay (mock) → submit
identity → submit bank details → submit a cheque guarantee → receive a
generated contract → accept it → sign it with a dedicated OTP → wait for an
admin. An admin can really review and approve or reject it. Every step writes
an audit row.

What does not exist at all: **the owner/lessor**, **the physical device**,
**pickup**, **inspection**, **delivery**, **return**, **damage**,
**settlement**, and **every notification**.

## 1.2 What is genuinely implemented [VERIFIED]

- Rental application chain with derived state — 171 tests, 1140 assertions, all
  passing
- Reservation with server-authoritative pricing and a price snapshot
- **Concurrency-safe booking** — 5 parallel requests for the same device and
  dates produced exactly 1 reservation
- Real date-range availability filtering in search
- Jalali calendar with correct leap rule
- Payment abstraction with a locked, idempotent settlement path (mock gateway)
- Contract generation, acceptance, OTP signing, integrity hash
- Fail-closed verification — a passing check did **not** promote an identity
  because no policy is defined
- Append-only audit trail — 23 rows written across my journey
- Admin review screens for applications, verifications and audit events
- CSRF enforced; no mass-assignment `$guarded`; no SQL injection surface; no
  lazy-loading N+1 on public pages

## 1.3 What is only foundation, mock or UI

- **Payment**: mock gateway only. No live credentials. [VERIFIED]
- **All four verification providers**: `fake` driver. No vendor chosen. [VERIFIED]
- **Wallet**: `localStorage` only. Zero backend artifacts. [VERIFIED]
- **SMS**: zero templates, zero messages sent during a full journey. [VERIFIED]
- **Contract**: generated and signed, but the template is explicitly marked as
  having no legal validity. [CODE]
- **Post-approval lifecycle**: mechanism exists, all triggers null, no route
  reaches it. [VERIFIED]

## 1.4 The biggest architectural gaps

1. **There is no owner/lessor domain.** Zero routes, zero models, zero tables.
   The system models "GamePek sells access to a catalog row", not "an owner's
   physical device is intermediated by GamePek". This is the single largest
   gap and it invalidates a large part of the intended lifecycle.
2. **There is no device unit.** One `Product` row is the rentable thing. Whether
   that is one physical console or a pool is undecided, and the reservation
   overlap logic silently assumes one-unit.
3. **The entire operations chain is absent** — pickup, inspection, delivery,
   return, damage, settlement.
4. **Two contradictory sources of availability truth**, proven inverted.
5. **Reservations are never released**, proven to permanently block inventory.

## 1.5 The biggest UX/design problems

1. **No component layer** — the primary button appears as 85 distinct class
   strings; 8 different border radii.
2. **Near-total absence of form labels** — 0–1 `<label>` per page against 3–7
   inputs.
3. **The product-page availability calendar shows fabricated data** — a
   customer is actively misinformed about what they can book.
4. **No loading states** anywhere; filtering and sorting are full page loads.
5. [UNKNOWN — REQUIRES VISUAL QA] Actual visual quality, spacing rhythm,
   responsive behaviour at breakpoints.

## 1.6 The biggest security risks

1. **P0 — No step gating: a customer paid 1,125,000 Toman with `identity: NONE`.**
   Verified live. Money moves before any identity exists.
2. **P1 — Reservation conflicts are never audited.** The audit row is written
   inside the transaction that then throws, so it rolls back. 5 real conflicts
   produced 0 audit rows.
3. **P1 — `/admin/users/{id}` returns HTTP 500** for every user.
4. **P1 — `.env.example` ships the Store's database name**, and three bundled
   setup scripts target the Store's directory and database.

## 1.7 The biggest business/legal unknowns

The owner model itself; whether a `Product` is a unit or a pool; all 14
`TODO(business)` decisions; contract legal validity; minors; data retention;
and every rule governing damage, late return, cancellation and settlement.

## 1.8 Overall completion — deliberately not inflated

| Dimension | Complete |
|---|---|
| Backend (customer application chain) | **60%** |
| Backend (whole intended business) | **25%** |
| Frontend (customer pages that exist) | **55%** |
| UX/UI (as a designed system) | **30%** |
| Rental **operations** (owner→pickup→inspect→deliver→return→settle) | **0%** |
| Integrations (payment, KYC, bank, cheque, SMS) | **10%** — all seams, no vendors |
| Admin | **35%** |
| Owner | **0%** |
| Testing | **55%** — excellent depth, wrong-shaped coverage |
| Production readiness | **12%** |

**Overall: ~25–30% of the intended platform.** The 70%+ remaining is
concentrated in the operational and owner-side domains that have not been
started, plus every external integration.

---

# 2. Current Architecture

**Stack [VERIFIED]:** Laravel `v11.54.0`, PHP 8.2.12, MariaDB 10.4.32, three
production dependencies (`laravel/framework`, `laravel/tinker`,
`spatie/laravel-permission`). No build step — Tailwind play CDN, Vazirmatn via
Google Fonts, Font Awesome via cdnjs, all JS inline in Blade.

**Layering:** Controllers → Services → Eloquent. No repository layer. Pure
logic isolated in `app/Support/` (Jalali, RentalItem, RentalQuote,
Availability, CardNumber, Iban, SayadId).

**Central principle [CODE, confirmed by tests]:** `RentalApplication.state` is
*derived*, never commanded. `RentalChainOrchestrator` is the sole writer.
`nextState()` is a pure predicate ladder; `advance()` locks the row, derives,
and writes nothing if unchanged. `state` is not fillable. Only `approve()`,
`reject()` and `cancel()` are non-derived.

**Route inventory [VERIFIED]:** 185 total — 122 admin, 40 public, 9 rental,
7 verification, 6 auth, 1 api.

---

# 3. Implemented Features

| Feature | Status | Evidence |
|---|---|---|
| OTP login (mobile) | **IMPLEMENTED** | [VERIFIED] logged in live; `dev_otp` returned; session established |
| Admin login (email+password) | **IMPLEMENTED** | [VERIFIED] 302 → `/admin/dashboard`; all admin pages 200 |
| Rental application creation | **IMPLEMENTED** | [VERIFIED] `RA-20260908-ATZVBP`, state `draft` |
| Reservation + price snapshot | **IMPLEMENTED** | [VERIFIED] `payable_now=1125000`, `deposit=12000000` |
| Concurrency-safe booking | **IMPLEMENTED** | [VERIFIED] 5 parallel → 1 true, 4 false, 1 DB row |
| Overlap rejection | **IMPLEMENTED** | [VERIFIED] sequential overlap rejected with Persian message |
| Date-range search filtering | **IMPLEMENTED** | [VERIFIED] booked device vanished from results, others remained |
| Jalali calendar | **IMPLEMENTED** | [CODE] + PHP-side round-trip tests pass |
| Mock payment + callback settlement | **IMPLEMENTED (MOCK)** | [VERIFIED] order `paid`, app state `paid` |
| Identity submission + masking | **IMPLEMENTED** | [VERIFIED] mask `04******99` |
| Fail-closed verification policy | **IMPLEMENTED** | [VERIFIED] check `passed` → identity `manual_review`, `kyc_level=1` |
| Audit trail | **IMPLEMENTED** | [VERIFIED] 23 rows incl. `identity.policy_undefined` = `denied` |
| Admin rejection | **IMPLEMENTED** | [VERIFIED] state → `rejected` |
| Contract generate/accept/sign | **IMPLEMENTED** | [CODE] + 63 dedicated tests pass |
| CSRF protection | **IMPLEMENTED** | [VERIFIED] request without token rejected |

---

# 4. Partial Features

| Feature | Status | Why not complete |
|---|---|---|
| Rental chain | **PARTIAL** | Ends at `Approved`. Everything after is blocked. |
| Availability | **PARTIAL** | Search is real; product-page calendar is fabricated. |
| Reservation lifecycle | **PARTIAL** | Only `Held` is ever written. 8 of 9 states are dead. |
| Identity verification | **PARTIAL** | Mechanism complete; fake provider; no policy; no liveness/face UI |
| Bank ownership | **PARTIAL** | Same shape; fake provider |
| Guarantee | **PARTIAL** | Same shape; fake provider; amount rule undefined |
| Contract | **PARTIAL** | Technically complete; legally unapproved |
| Admin panel | **PARTIAL** | Application review works; all operations workflows absent; one page 500s |
| Cart/checkout | **PARTIAL** | Inherited from Store; rentals correctly rejected from cart |

---

# 5. Mock / Unconfigured Features

| Feature | State | Evidence |
|---|---|---|
| Payment gateway | **MOCK** | `PAYMENT_GATEWAY=mock`; `MockGateway` unresolvable outside local/testing [CODE] |
| Pardakht Novin | **UNCONFIGURED** | Adapter exists, no credentials [VERIFIED config] |
| Zarinpal / IDPay | **UNCONFIGURED** | In `.env.example` but not registered → `UnconfiguredGateway` |
| Identity provider | **MOCK** | driver `fake` |
| Bank ownership provider | **MOCK** | driver `fake` |
| Cheque/Sayad provider | **MOCK** | driver `fake` |
| General SMS | **UNCONFIGURED** | driver `log`; 0 templates; 0 messages [VERIFIED] |
| OTP SMS | **UNCONFIGURED** | `MELIPAYAMAK_API_KEY` empty; `NullOtpProvider` |
| Signature | **MOCK-GRADE** | Internal HMAC, explicitly not PKI [CODE] |

---

# 6. Broken Features

### 6.1 `/admin/users/{id}` — HTTP 500 for every user [VERIFIED]

```
user 1: HTTP 500   user 2: HTTP 500   user 3: HTTP 500   user 4: HTTP 500
Exception class in response: BadMethodCallException
```

`Admin\UserController::show()` calls
`loadCount(['orders','wishlists','reviews','questions'])`. `User` has no
`wishlists`, `reviews` or `questions` relations — pruned Store features.
`admin/users/show.blade.php` also reads `wishlists_count`, `reviews_count`,
`questions_count`. **Severity: HIGH.** No test covers it.

### 6.2 `reservation.conflict` audit rows are silently discarded [VERIFIED — NEW]

5 real conflicts occurred during testing. `AuditEvent` count for
`reservation.conflict`: **0**.

Cause: `RentalReservationService::reserve()` writes the audit row *inside*
`DB::transaction()` and then throws. The throw rolls the transaction back,
taking the audit row with it. This is precisely the failure mode
`RentalChainOrchestrator::approve()` documents and guards against by auditing
*before* the transaction — `reserve()` does not.

**Impact:** booking conflicts, including deliberate availability probing, are
invisible to the audit trail. **Severity: HIGH** (audit integrity).

### 6.3 The product-page availability calendar shows fabricated data [VERIFIED]

Proven in both directions on `ps5-slim-digital`:

| | Calendar says | Reality |
|---|---|---|
| 2026-09-14 → 09-17 | **RESERVED** | **Free** — search returns it; reservation succeeded |
| 2026-10-01 → 10-03 | **FREE** | **Paid and booked** — search excludes it |

The calendar reads `attributes._rental.blocked`, a static JSON array from the
demo seeder. Search reads `rental_reservations`. **Severity: CRITICAL** —
customers are actively misinformed.

---

# 7. Missing Features

**Entirely absent — 0 routes, 0 models, 0 tables [VERIFIED via route + schema enumeration]:**

Owner/lessor · Device · Device unit · Owner device registration · Owner
availability management · Owner verification · Owner payout/settlement ·
Pickup · Physical inspection (any of the four points) · Delivery · Return ·
Damage assessment · Late-return handling · Customer cancellation · Refunds ·
Dispute resolution · Wallet backend · Wallet ledger · Any lifecycle
notification · Contract template admin UI · Sitemap · Structured data ·
Open Graph · Loading/skeleton states

---

# 8. Backend Audit

**Inventory [VERIFIED]:** 184 PHP files — 39 models, 9 enums, 4 policies,
2 middleware, 3 console commands, 17 form requests, ~50 service classes,
2 providers.

**Absent by design:** Events, Listeners, Jobs, Notifications, Repositories.
Everything is synchronous and inline.

**Strengths [CODE + VERIFIED]:**
- Single-writer state discipline, structurally idempotent
- `lockForUpdate()` at all three contention points (reservation, payment
  settlement, chain advance) — reservation locking proven under real concurrency
- Uniform integration seam: interface + DTO + Fake + Unconfigured
- Unconfigured providers throw; fakes refused outside local/testing
- Persian business exceptions; raw exception text never surfaced

**Risks:**

| Risk | Severity | Evidence |
|---|---|---|
| No step gating before reserve/pay | **CRITICAL** | [VERIFIED] paid with `identity: NONE` |
| Conflict audit rolled back | **HIGH** | [VERIFIED] 0 rows / 5 conflicts |
| Reservation states 2–9 never written | **HIGH** | [VERIFIED] `held` after payment and after rejection |
| No queue — all provider calls synchronous | **MEDIUM** | `QUEUE_CONNECTION=database` but no jobs exist |
| No customer cancellation path | **MEDIUM** | No route exists |
| Unlimited concurrent draft applications per user | **LOW–MEDIUM** | [VERIFIED] 8 created for one user in minutes |
| Post-approval mechanism unreachable | **BY DESIGN** | No route; triggers null |

---

# 9. Database Audit

**54 tables, 53 foreign keys, 30 CHECK constraints [VERIFIED].** 63 migrations
applied, forward-only.

## Domain entity map

| Entity | Exists | Notes |
|---|---|---|
| USER | ✅ | Schema deliberately Store-compatible for a planned import |
| CUSTOMER | ✅ | Same table; role-based |
| **OWNER** | ❌ | **No table, no model, no concept** |
| **DEVICE** | ❌ | Only `products` — a catalog row, not a physical asset |
| **DEVICE UNIT** | ❌ | No serial, no unit, no per-unit availability |
| CATEGORY | ✅ | Inherited |
| AVAILABILITY | ⚠️ | Two sources: `rental_reservations` (real) + `attributes._rental.blocked` (fake) |
| RESERVATION | ✅ | Good schema, snapshots, range index; lifecycle unused |
| PAYMENT | ✅ | `orders` + `payment_transactions` with verification columns |
| KYC | ✅ | `user_identities` (satellite) + `identity_verifications` |
| BANK | ✅ | `bank_accounts`, encrypted + hash + mask |
| GUARANTEE | ✅ | `guarantees` + `guarantee_inquiries` |
| CONTRACT | ✅ | `contract_templates` + `contracts` + `contract_signatures` + `contract_signature_otps` |
| **INSPECTION** | ❌ | Missing entirely |
| **PICKUP** | ❌ | Missing entirely |
| **DELIVERY** | ❌ | Missing entirely |
| **RETURN** | ❌ | Missing entirely |
| **DAMAGE** | ❌ | Missing entirely |
| **SETTLEMENT** | ❌ | Missing entirely |
| **WALLET** | ❌ | **Zero artifacts** [VERIFIED: 0 matches for `wallet_balance`/`wallet_transactions`] |
| SMS | ⚠️ | Table exists, 0 rows after a full journey |
| AUDIT | ✅ | `audit_events` append-only, working |
| CONSENT | ⚠️ | Table exists, **0 rows** — nothing writes consent |

## Integrity observations

- FK policy is sound: `restrictOnDelete` for retention-sensitive parents,
  `cascadeOnDelete` only for dependent children
- Sensible unique constraints: `national_code_hash`, `sayad_id_hash`,
  `(user_id, value_hash)`, `(contract_id, user_id)`, `application_number`
- `rental_reservations_range_idx (product_id, start_date, end_date)` supports
  the overlap query
- **No DB-level overlap exclusion** — MySQL cannot express it; protection is
  purely the application lock (which works, but is the only line of defence)
- `verification_media.rental_application_id` is an unconstrained
  `unsignedBigInteger` — indexed but **no foreign key** [CODE]
- `consents` is orphaned in practice

---

# 10. Rental Domain Audit

## 10.1 The central architecture gap

**Currently modelled:** GamePek owns a catalog. A `Product` carries rental
facts in a JSON blob. A customer reserves a date range on that row.

**Intended:** An owner owns a physical device. GamePek intermediates: receives,
inspects, delivers, recovers, re-inspects, settles, returns.

These are not the same system. The current model has **no party on the supply
side**, no physical asset, no custody chain, and no money flowing outward.

## 10.2 Required for the intended model — none of which exists

| Concept | Needed | Present |
|---|---|---|
| Owner identity & role | Owner account, KYC, payout details | ❌ |
| Device | Physical asset: brand, model, serial, condition, accessories | ❌ |
| Device unit | The bookable physical instance | ❌ |
| Ownership link | Device → Owner | ❌ |
| Owner availability | Owner-declared windows, blackouts | ❌ |
| Device approval | Admin review of a submitted device | ❌ |
| Custody | Who physically holds it right now | ❌ |
| Inspection record | Structured, at 4 handover points | ❌ |
| Settlement | Owner payout, GamePek commission | ❌ |

**Because none of this exists, the following intended lifecycle steps cannot be
started without a domain design first:** device registration, owner
verification, availability publishing, pickup, all inspections, delivery,
return, damage, settlement, owner payout.

## 10.3 Undecided, blocking [UNKNOWN — REQUIRES OWNER DECISION]

1. GamePek-owned fleet, or third-party marketplace? Everything above depends
   on this answer.
2. One `Product` = one physical device, or a pool? The overlap logic assumes
   one; a pool makes overlap ≠ conflict.
3. Does the `Product`/`products` entity get renamed/split into
   Device + DeviceUnit?

---

# 11. State Machine Audit

## 11.1 Documented chain — verified against code [CODE + tests]

```
Draft → IdentityPending → IdentityVerified → BankPending → BankVerified
      → ReservationHeld → PaymentPending → Paid
      → GuaranteePending → GuaranteeVerified
      → ContractGenerated → ContractAccepted → ContractSigned
      → AwaitingFinalApproval → Approved
```

Confirmed accurate. Plus terminal `Rejected` / `Cancelled`.

**But the ladder describes progress, it does not gate it.** [VERIFIED] I went
`draft` → `reservation_held` → `payment_pending` → `paid` while
`identity: NONE`. `nextState()` returns the *highest rung whose facts hold*, so
skipping lower rungs is invisible and legal.

## 11.2 Post-approval — audited against the intended chain

| Intended state | Exists in enum | Reachable | Trigger | UI | Audit | Notification |
|---|---|---|---|---|---|---|
| Owner Pickup Pending | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Device Received | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Physical Inspection | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Inspection Approved | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Delivery Pending | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Delivered | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Active Rental** | ✅ `Active` | ❌ | null (B14) | ❌ | ✅ mechanism | ❌ |
| Return Pending | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Returned** | ✅ `Returned` | ❌ | null (B14) | ❌ | ✅ mechanism | ❌ |
| Final Inspection | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Damage Assessment | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Settlement | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Closed** | ✅ `Closed` | ❌ | null (B14) | ❌ | ✅ mechanism | ❌ |
| Owner Settlement | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

**3 of 14 intended post-approval states exist as enum values; 0 are reachable;
11 are entirely absent.** `transitionPostApproval()` refuses every call and
logs `policy_undefined`. No route exposes it. This is deliberate (B14), not
accidental — but it means an approved rental is a dead end today.

---

# 12. Availability Audit

**Source of truth:** `rental_reservations`, via a `blocking()` scope covering
`held | awaiting_payment | paid | active`, joined by an overlap predicate.

**Second, contradictory source:** `products.attributes._rental.blocked`, a
static JSON array read by `RentalItem::blocked()` → `RentalCalendar::build()`
→ the product-page calendar. **Fabricated by the demo seeder.** [VERIFIED as
inverted in both directions — §6.3]

**Dates:** stored as `date` columns in Gregorian; Jalali exists only at the
presentation layer, converted in both PHP (`Support\Rental\Jalali`) and a JS
port. Day counting is inclusive (`end = start + days − 1`) [VERIFIED:
3 days from 2026-10-01 → end 2026-10-03].

## What blocks, and what never unblocks [VERIFIED]

| Event | Reservation state after | Still blocks? |
|---|---|---|
| Reserved | `held` | yes (correct) |
| **Paid** | **`held`** | yes — but should be `paid` |
| **Application rejected** | **`held`** | **yes — WRONG, permanent leak** |
| Application cancelled | `held` | yes — WRONG (same code path) |
| Hold expiry | n/a | never fires — `hold_minutes` is null (B10) |
| Payment failed | `held` | yes — WRONG |
| Abandoned draft | `held` | yes — WRONG, forever |
| Owner cancels | n/a | no owner exists |

**Proven:** after admin rejection, `ps5-slim-digital` remained absent from
search for 2026-09-14→18. The device is permanently un-rentable for those dates
because of a rejected application.

## Race conditions

**No double-booking race found.** [VERIFIED] 5 concurrent identical requests →
1 success, 4 rejections, exactly 1 DB row. `Product::lockForUpdate()` correctly
serialises before the overlap check.

**Residual concern:** the lock is on `products.id`. If a `Product` later becomes
a *pool* of units, this lock serialises the whole pool (a scalability issue) and
the overlap check becomes semantically wrong (an overlap would no longer imply a
conflict).

---

# 13. Payment Audit

| Aspect | Status | Evidence |
|---|---|---|
| Request / redirect | ✅ | [VERIFIED] gateway URL issued |
| Callback parsing | ✅ | `GatewayCallback` has **no `success` field** by design [CODE] |
| Verification | ✅ | `verify()` is the sole source of "paid" [CODE] |
| Amount validation | ✅ | Mismatch → `failed` [CODE, tested] |
| Idempotency | ✅ | Locked; already-successful returns stored result [CODE, tested] |
| Replay protection | ✅ | `PaymentCallbackTest` (6 tests) passes |
| Locking | ✅ | `lockForUpdate` in `settle()` |
| Unknown status | ✅ | Stays `pending` for reconciliation |
| Reconciliation | ✅ | `payments:reconcile` command exists |
| Refund | ⚠️ | Interface method exists; **no business rule, never called** |
| Cancelled payment | ✅ | Mock supports it |
| Timeout | ⚠️ | Handled as `Unknown` → reconcile |
| **Live gateway** | ❌ | **None. `PAYMENT_GATEWAY=mock`** |

**Production guard verified [CODE]:** `GatewayRegistry` makes `mock`
unresolvable outside `local|testing`; `MockPaymentController` 404s outside those
environments and checks transaction ownership.

**Store impact:** none. Separate application, separate database, separate
config. No shared code path. [VERIFIED — separate DBs confirmed]

**The critical payment finding is not in the payment layer at all** — it is that
`pay()` has no verification precondition (§6/§11.1).

---

# 14. KYC / Identity Audit

| Check | Implemented | Provider |
|---|---|---|
| National ID (structure + storage) | ✅ | internal |
| Shahkar | ✅ mechanism | **fake** |
| Civil registry | ✅ mechanism | **fake** |
| Liveness | ✅ schema/type | **fake**, no capture UI found |
| Face match | ✅ schema/type | **fake**, no capture UI found |
| OTP (login) | ✅ | `NullOtpProvider` (unconfigured) |

**Storage [VERIFIED]:** encrypted + HMAC hash + mask. Mask rendered as
`04******99`; raw value never reaches Blade.

**Fail-closed [VERIFIED]:** `required_checks = []` → a *passing* Shahkar check
left the identity at `manual_review`, `kyc_level = 1`, and wrote
`identity.policy_undefined` as `denied`. This works exactly as designed.

**Rate limiting [VERIFIED in route table]:** `verification-identity` (6/10min),
`verification-media` (10/10min), `verification-bank` (6/10min) — named
limiters, correctly avoiding the shared-bucket trap.

**Missing business rules — all [REQUIRES BUSINESS OWNER DECISION]:** B1 required
checks, B2 score thresholds, B3 attempt cap, rejection/appeal behaviour, manual
review SLA.

**Missing entirely:** minors handling, guardian consent, and any age gate.
`consents` table has **0 rows** — no consent is captured anywhere.
[REQUIRES LEGAL REVIEW]

---

# 15. Bank Ownership Audit

| Aspect | Status |
|---|---|
| Card structural validation (Luhn) | ✅ `Support\Banking\CardNumber` |
| IBAN validation (mod-97) | ✅ `Support\Banking\Iban` |
| BIN → bank name | ⚠️ [UNKNOWN] not verified in this audit |
| Card → IBAN resolution | ⚠️ schema field `linked_iban_mask` exists; provider fake |
| Owner name retrieval | ⚠️ fake provider |
| **Card ↔ national ID match** | ⚠️ `ownership_match` field exists; **fake provider decides** |
| Encryption + hash + mask | ✅ |
| Unique per user | ✅ `unique(user_id, value_hash)` |
| Audit | ✅ |
| Rate limit | ✅ named limiter |

**Does the system prove "the bank account belongs to the authenticated user"?**

**No.** [VERIFIED] The mechanism to record that proof is complete and correct,
but the answer currently comes from `FakeBankOwnershipProvider`, which is
deterministic local fiction. Until a real provider is wired, **no ownership is
actually proven**.

---

# 16. Guarantee / Cheque / Sayad Audit

| Aspect | Status |
|---|---|
| Sayad ID structural validation | ✅ 16 digits, not-all-identical |
| Sayad checksum | ⚠️ implemented but **disabled** (`enforce_sayad_checksum=false`) — no bank spec available to confirm the rule [CODE] |
| Sayad validate inquiry | ⚠️ **fake** |
| Ownership match inquiry | ⚠️ **fake** |
| Cheque status / bounced cheque | ❌ `GuaranteeInquiry` kinds exist; no real provider |
| Credit inquiry | ❌ |
| Encryption (encrypted+hash+mask) | ✅ |
| Unique per cheque | ✅ `sayad_id_hash` unique |
| Auto-verify | ❌ **never** — `required_inquiries` empty; always waits for admin |
| Blocking / release / enforcement | ❌ **no mechanism at all** |

**Undecided [REQUIRES BUSINESS OWNER DECISION]:** B5 amount formula, B6 mandatory
inquiries, B7 risk thresholds, and the entire question of how a guarantee is
*used* — blocked, released, or enforced. There is no code for enforcement.

---

# 17. Contract / Signature Audit

| Aspect | Status | Evidence |
|---|---|---|
| Generation from versioned template | ✅ | `unique(key, version)`; published rows immutable |
| Gated on `GuaranteeVerified` | ✅ | Contract rungs also require guarantee [CODE] |
| Snapshot + `content_hash` (sha256) | ✅ | Signed contract independent of template |
| Rendering safety | ✅ | `strtr` over `e()`-escaped values; **never `Blade::render()`** [VERIFIED in `TemplateRenderer`] |
| Explicit acceptance | ✅ | Opening the page is not acceptance; `accepted_by_user_id` recorded |
| Signing OTP separate from login OTP | ✅ | Own table, own limiter, one-time, expiry, attempt cap |
| Signature | ✅ | `hash_hmac(sha256, contentHash\|userId\|signedAt, APP_KEY)` |
| Integrity check at approval | ✅ | `isIntact()` + signature verification before `Approved` |
| Test coverage | ✅ | 63 tests across 3 contract test files, all passing |
| **Legal validity** | ❌ | **Explicitly disclaimed in code: not PKI, no eIDAS-equivalent weight** |
| Template content | ❌ | Placeholder (B12) |

**Residual XSS note:** `contract.blade.php` outputs `{!! $contract->rendered_html !!}`.
Substituted *values* are escaped, but the template *body* is raw. There is
currently **no admin UI for contract templates** [VERIFIED — no such route], so
this requires database access to exploit. Low risk today; becomes MEDIUM the
moment a template editor ships.

**Is it production/legal ready? No.** [REQUIRES LEGAL REVIEW] — both the text
and the signature method.

---

# 18. SMS / Notification Audit

**There is no event-based notification system.** [VERIFIED]

After a complete live journey — application, reservation, payment, rejection,
identity submission — `sms_messages` contained **0 rows**.

- `config('rental.sms.templates')` = `[]`
- `config('rental.sms.state_templates')` = `[]`
- driver = `log`

| Lifecycle event | Notification |
|---|---|
| identity submitted / approved / rejected | ❌ |
| bank verification | ❌ |
| reservation | ❌ |
| payment | ❌ |
| guarantee | ❌ |
| contract / signature | ❌ |
| final approval | ❌ |
| pickup / received / inspection / delivery | ❌ (states don't exist) |
| rental start / ending / return | ❌ |
| final inspection / settlement / owner return | ❌ |
| cancellation / refund | ❌ |

**Is Store OTP SMS being mistaken for notification infrastructure?**
**No — correctly separated.** [CODE] OTP uses `OtpProviderInterface` +
MeliPayamak in `config/services.php`; general SMS uses `SmsSenderInterface` +
`config/verification.php`. The separation is deliberate and documented.

The infrastructure (`SmsService`, `sms_messages`, retry/attempts, delivery sync
command, audit) exists. **Only the copy is missing (B13).**

---

# 19. Wallet Audit

**Requested inspection of `wallet-grok/`: the directory does not exist in this
repository.** [VERIFIED] It is listed in `.gitignore` and was never committed.
Its contents are **[UNKNOWN]**.

## UI COMPLETE vs BACKEND COMPLETE

| Item | UI | Backend |
|---|---|---|
| Balance display | ✅ (always ۰, localStorage) | ❌ no column |
| Top-up | ✅ mock | ❌ not connected to gateway |
| Withdraw | ✅ mock | ❌ |
| Transaction history | ✅ mock | ❌ no ledger table |
| Card number field | ✅ present | ❌ |
| Sheba/IBAN field | ✅ present | ❌ |
| Wallet ID | ✅ derived `GP-000002` | ❌ not persisted |
| Connected to Profile | ✅ tab in profile | — |
| Admin wallet destination | ⚠️ 19-line view reading «به‌زودی» | ❌ placeholder |
| **Dark mode** | ❌ **0 `dark:` classes anywhere** | — |
| Ownership requirement communicated | ⚠️ [UNKNOWN — REQUIRES VISUAL QA] |
| Manual withdrawal messaging | ⚠️ [UNKNOWN — REQUIRES VISUAL QA] |

**Backend artifacts for wallet: 0.** [VERIFIED — no `wallet_balance`,
`WalletTransaction` or `wallet_transactions` anywhere in `app/` or `database/`]

Does successful payment increase balance? **No.** Does failed payment leave it
unchanged? **Trivially yes — there is no balance.**

**No figure in the wallet UI represents money.**

---

# 20. Customer UX Audit

**Method limitation:** markup analysis only; no rendering. Scores marked
[UNKNOWN] where visual judgement is required.

| Page | HTTP | Structural quality | Key problems |
|---|---|---|---|
| Home | 200 | 6/10 | **2 `<h1>`**; 3/10 images have alt; 5 icon-only buttons unlabelled; no loading states |
| Product list | 200 | 6/10 | 7/10 alt; no skeletons; full-page reload on filter |
| Product detail | 200 | **4/10** | **Calendar shows fabricated availability (CRITICAL)**; 8/15 alt; 0 `<label>` for 3 inputs |
| Search | 200 | 6/10 | 3/10 alt; empty state [UNKNOWN] |
| Cart | 200 | 6/10 | 0 `<label>`; rentals correctly excluded |
| Login | 200 | [UNKNOWN] | No `<form>` element — JS-only submit; **fails without JS** |
| Profile | 200 | 5/10 | Wallet tab presents mock data as real |
| Rental application | 200 | [UNKNOWN] | Step list renders; no gating enforced behind it |
| Contract | 200 | [UNKNOWN] | Raw HTML block |
| About / Contact / Terms | 200 | 6/10 | Static; Terms hardcoded in Blade |

**Cross-cutting UX problems [VERIFIED at markup level]:**

1. **No loading states anywhere** — `0` skeleton/spinner markup found
2. **Form labels essentially absent** — 0–1 `<label>` per page vs 3–7 inputs;
   placeholder-as-label is an accessibility and usability failure
3. **Icon-only buttons with no accessible name** — 5 on the homepage
4. **Login requires JavaScript** — no `<form>` fallback
5. **Two `<main>` elements** on every page — invalid HTML
6. **`placehold.co` external placeholder** referenced in production markup

**Trust concerns (material for a rental business handling money, identity,
contracts):** the wallet presents fake balances; the calendar presents fake
availability; both erode exactly the trust this product needs most.

---

# 21. Owner UX Audit

**There is no owner experience. [VERIFIED — 0 routes matching
owner|device|unit|inspect|pickup|deliver|return|settle]**

| Owner capability | Exists |
|---|---|
| Create account | ⚠️ only as a generic user |
| Complete verification | ⚠️ generic KYC only, not owner-scoped |
| Register a device | ❌ |
| Enter serial/model | ❌ |
| Upload device media | ❌ |
| Select accessories | ❌ |
| Define availability | ❌ |
| See verification status | ❌ |
| See admin decision | ❌ |
| Reservation notification | ❌ |
| Hand device to GamePek | ❌ |
| Pickup receipt | ❌ |
| See rental status | ❌ |
| See income | ❌ |
| See settlement | ❌ |
| Receive device back | ❌ |
| Return receipt | ❌ |

**Every screen, API, model, service and state for the owner role must be
introduced from zero — after the domain decision in §10.3.**

---

# 22. Admin UX Audit

| Capability | Status | Evidence |
|---|---|---|
| Users list | ✅ | 200 |
| **User detail** | ❌ **500** | [VERIFIED] |
| Owners | ❌ | no concept |
| Customers | ⚠️ | via users |
| Devices / units / availability | ❌ | no concept |
| Reservations | ⚠️ | only via application detail; no reservation list/管理 |
| Rental applications | ✅ | list + detail + approve/reject/refresh |
| KYC review | ✅ | queue + approve/reject |
| Bank verification | ⚠️ | visible in application detail; no dedicated queue |
| Guarantees | ✅ | verify/reject from application detail |
| Contracts | ⚠️ | void only; no template management |
| Payments | ✅ | list/detail/approve/reject (inherited) |
| Pickup / inspection / delivery / returns / damage / settlement / payouts | ❌ | **none exist** |
| Refunds / cancellations | ❌ | no workflow |
| Audit logs | ✅ | filterable |
| SMS / notifications | ❌ | no admin surface |
| Configuration | ✅ | settings groups |
| Wallet | ⚠️ | «به‌زودی» placeholder |

**Roughly 60% of the operational admin surface the intended business requires
does not exist.** For each missing workflow the who/what/state/audit/notification
design is specified in §35 (Master Remaining Work), not invented here.

---

# 23. Design System Audit

**There is no design system — there is a palette.** [VERIFIED]

`design-tokens.blade.php` (59 lines) defines **6 colors**, **1 font family**,
and 6 utility CSS rules. No spacing scale, no radius scale, no typographic
scale, no component classes.

## Measured drift across `resources/views/`

| Dimension | Distinct values | Detail |
|---|---|---|
| **Primary button class strings** | **85** | every `bg-brandBlue` element styled ad hoc |
| Border radius | **8** | `xl`(585), `2xl`(184), `full`(166), `lg`(150), `3xl`(8), `sm`(5), `md`(5), `none`(3) |
| Shadow | **5** | `sm`(169), `lg`(30), `md`(14), `2xl`(9), `xl`(4) |
| Button padding pairs | **12+** | `px-4 py-3`(403), `px-4 py-2.5`(179), `px-4 py-2`(68), … |

## Design system issue list

1. **CRITICAL** — no button component; 85 variants of one control
2. **HIGH** — 8 radius values with no rule for which to use
3. **HIGH** — no form input component; labels missing; error styling ad hoc
4. **HIGH** — no loading/skeleton primitives (0 found)
5. **MEDIUM** — 5 shadow levels with no elevation model
6. **MEDIUM** — no empty-state component
7. **MEDIUM** — status/badge colours not centralised (rental states, order
   states, verification states each styled per view)
8. **MEDIUM** — 12+ button padding combinations
9. **LOW** — icon usage not standardised (Font Awesome used freely)
10. **NONE** — dark mode: 0 `dark:` classes; not attempted

---

# 24. Responsive Audit

[UNKNOWN — REQUIRES VISUAL QA] — no browser available.

**What can be confirmed from markup [CODE]:**
- Responsive utility classes are used throughout (`md:`, `flex-col md:flex-row`)
- A dedicated mobile bottom nav exists (`partials/mobile-nav.blade.php`)
- Wide tables wrapped in `overflow-x-auto`
- `main { overflow-x: hidden }` with a documented iOS Safari rationale
- `.pb-safe` uses `env(safe-area-inset-bottom)`
- A full-screen mobile category modal with RTL-correct slide direction

**What cannot be confirmed without rendering:** actual breakpoint behaviour,
touch target sizes (44px minimum), calendar usability on mobile, admin table
behaviour on small screens, whether the fixed price/buy bar behaves correctly,
horizontal overflow in practice.

---

# 25. Accessibility Audit

| Check | Result |
|---|---|
| `lang="fa" dir="rtl"` | ✅ correct |
| Landmarks | ⚠️ header/footer/nav present, but **2 `<main>` elements** (invalid) |
| Skip link | ❌ none |
| **Form labels** | ❌ **home 1 label / 7 inputs; detail, cart 0 labels / 3 inputs** |
| Icon-only button names | ❌ 5 unlabelled on home, 3 on search |
| ARIA usage | ⚠️ 4 attributes on most pages; 50 on product detail (the calendar) |
| Focus styles | ⚠️ only 8 `focus:` declarations per page |
| Keyboard support | ⚠️ calendar documented as keyboard-operable; rest [UNKNOWN] |
| Contrast | [UNKNOWN — REQUIRES VISUAL QA] |
| Screen reader flow | [UNKNOWN] |
| Modal focus trap | [UNKNOWN] |
| Touch targets | [UNKNOWN — REQUIRES VISUAL QA] |

**Assessment:** below baseline. The label gap alone would fail WCAG 2.1 A.

---

# 26. SEO Audit

## Currently implemented [VERIFIED across 9 pages]

- ✅ Unique, Persian `<title>` per page
- ✅ `<meta name="description">` on every page (117–132 chars)
- ✅ `robots.txt` present (HTTP 200)
- ✅ Clean, slug-based URLs (`/products/ps5-slim-digital`)
- ✅ `lang="fa"`, semantic headings mostly present

## Needs implementation [VERIFIED absent — count = 0 on every page]

- ❌ **Open Graph / Twitter Card** — 0 tags
- ❌ **Canonical URLs** — 0
- ❌ **Structured data (JSON-LD)** — 0. No Product, Offer, Breadcrumb,
  Organization or LocalBusiness schema
- ❌ **`sitemap.xml`** — HTTP 404
- ❌ **Image alt coverage** — home 3/10, search 3/10, cart 3/6, detail 8/15
- ❌ **Heading hierarchy** — homepage has **2 `<h1>`**
- ❌ Breadcrumbs
- ❌ Pagination `rel` hints
- ⚠️ **Core Web Vitals risk**: Tailwind play CDN compiles CSS *in the browser*
  on every page load — a large, blocking LCP/CLS penalty. Google Fonts and
  cdnjs add third-party latency, both notably unreliable from Iran.
- ⚠️ Page weight 51–112 KB of HTML plus ~31 KB inline JS on the homepage

---

# 27. Performance Audit

**Positive, and strong [VERIFIED]:**

`Model::preventsLazyLoading()` returns **YES** in this environment, and every
public page renders HTTP 200. Under strict mode a lazy-loaded relation throws.
Therefore **there are no lazy-loading N+1 problems on the rendered public
paths** — this is proof, not inspection.

**Measured server response (4 products, warm):**

| Page | Times (s) |
|---|---|
| `/` | 0.195 / 0.152 / 0.160 |
| `/products` | 0.147 / 0.150 / 0.154 |
| `/products/{slug}` | 0.154 / 0.181 / 0.171 |
| `/search?city&from&to` | 0.166 / 0.165 / 0.164 |

**Bottleneck risks:**

| Risk | Severity |
|---|---|
| Tailwind play CDN compiles in-browser every load | **HIGH** (client-side) |
| Google Fonts + cdnjs from Iran | **HIGH** (availability + latency) |
| No caching layer on catalog/search queries | MEDIUM |
| Search overlap query with `whereDoesntHave` at scale | MEDIUM — index exists, untested beyond 4 products |
| Admin tables without visible pagination limits on some reports | MEDIUM [UNKNOWN at scale] |
| All provider calls synchronous (no queue) | MEDIUM — a slow KYC vendor will block the request |
| 31 KB inline JS on homepage, uncacheable | LOW–MEDIUM |
| No image optimisation/responsive images | [UNKNOWN] |

**Not tested:** behaviour at realistic data volumes. All timings reflect a
4-product, 4-user dataset and should not be read as scalability evidence.

---

# 28. Security Audit

## Verified strengths

| Control | Evidence |
|---|---|
| CSRF | [VERIFIED] request without token → "CSRF token mismatch" |
| Mass assignment | [VERIFIED] **0 models use `$guarded`** — all use `$fillable` |
| SQL injection | [VERIFIED] 26 `DB::raw` uses, all static literals, no interpolation |
| XSS | [VERIFIED] only 2 `{!! !!}`; one is `nl2br(e(...))`, other is the contract with escaped substitution |
| Sensitive data | [VERIFIED] encrypted + HMAC + mask; `04******99` rendered |
| Audit redaction | [CODE] 13 keys stripped at any depth |
| Private media | [CODE] private disk, signed URL, ownership check, audited read |
| Signing OTP isolation | [CODE] separate table, separate limiter |
| Rate limiting | [VERIFIED in route table] 6 named limiters correctly applied |
| Mock gateway gating | [CODE] unresolvable outside local/testing + ownership check |
| Fake provider gating | [CODE] refused outside local/testing |
| Payment replay | [VERIFIED] 6 callback tests pass |
| Reservation race | [VERIFIED] 5 concurrent → 1 |

## Findings

| # | Finding | Risk |
|---|---|---|
| S1 | **No verification gating before payment.** A user paid 1,125,000 T with `identity: NONE`. Money and a legal commitment precede any identity. | **CRITICAL** |
| S2 | **Conflict audit rows roll back** — 5 conflicts, 0 rows. Availability probing is invisible. | **HIGH** |
| S3 | **`.env.example` ships `DB_DATABASE=gamepek`** (Store DB); composer's `post-root-package-install` copies it automatically. | **HIGH** |
| S4 | **Three bundled setup scripts target the Store**: `setup.sh`, `install.bat`, `setup-xampp.bat` create/migrate `gamepek` and two hardcode `C:\Users\Hossein Mt\Desktop\GamePek\gamepek-backend`. **The Store directory exists on this machine.** | **HIGH** |
| S5 | `/admin/users/{id}` 500s — information disclosure via stack trace when `APP_DEBUG=true`. | MEDIUM |
| S6 | Contract `rendered_html` output raw; template body admin-authored. No template UI today. | LOW → MEDIUM when an editor ships |
| S7 | `verification_media.rental_application_id` has no FK. | LOW |
| S8 | No customer cancellation path; no session-level application limit (8 drafts created in minutes). | LOW |
| S9 | `consents` table empty — no consent captured before identity/bank/cheque collection. | **LEGAL** |
| S10 | No age gate; minors can complete the entire flow. | **LEGAL** |

**No backdoors, hardcoded production credentials, or debug endpoints were
found.** Seeded local credentials are documented and environment-gated.

---

# 29. Testing Audit

**Measured, not quoted [VERIFIED by execution]:**

```
PHPUnit 11.5.55 — OK (171 tests, 1140 assertions)   Time: 31.185s
```

| Metric | Actual |
|---|---|
| Test files | 15 (+1 `TestCase`, +1 trait) |
| Test methods | **171** |
| Assertions | **1140** |
| Feature tests | 170 |
| Unit tests | 1 (`SmokeTest`) |
| Browser tests | **0** |
| Pass rate | **100%** |

Documentation says 170/1135 in one place and 52/517 in another — both stale;
actual is 171/1140.

## Coverage by file

| File | Tests |
|---|---|
| RentalContractSigningTest | 22 |
| RentalContractTest | 21 |
| RentalPostApprovalLifecycleTest | 20 |
| RentalContractAcceptanceTest | 20 |
| RentalGuaranteeTest | 18 |
| RentalFinalApprovalTest | 18 |
| RentalChainEndToEndTest / NavigationAndCalendarTest | 10 each |
| HomeSearchFlowTest | 9 |
| PaymentCallbackTest | 6 |
| RentalBookingJourneyTest / AuditLoggerTest / AdminRentalScreensTest | 5 each |
| DatabaseMigratesTest / SmokeTest | 1 each |

## Critical missing tests

| Gap | Why it matters |
|---|---|
| **Concurrent reservation** | I proved it works manually — nothing locks that in |
| **Reservation release on reject/cancel** | The bug exists *because* nothing tests it |
| **Availability source consistency** | Would have caught the fabricated calendar |
| **Step gating before pay** | Would have caught the CRITICAL finding |
| **Conflict audit persistence** | Would have caught the rollback bug |
| `/admin/users/{id}` rendering | Would have caught the 500 |
| Reservation expiry | Untestable — no policy |
| Wallet accounting | No backend to test |
| Owner/inspection/return/settlement | Domains don't exist |
| Any browser/visual test | 0 exist |

**Assessment:** the tests are genuinely good *within the boundary the authors
drew* — the chain mechanism is thoroughly locked down. But the four most severe
defects found in this audit all sit **outside** that boundary, in the seams
between components. Depth is excellent; breadth is the gap.

---

# 30. Documentation Audit

| Document | Accuracy |
|---|---|
| `CLAUDE.md` | **Updated 2026-09-08** (prior task, uncommitted). Now accurate. |
| `README.md` | ⚠️ **STALE** — "contains **only the reusable foundation**… No rental business logic is implemented yet". **False.** Also claims "No automated tests — `tests/` is an empty scaffold" — **false, 171 pass.** |
| `docs/rental-flow-fa.md` | ✅ Architecturally accurate; ⚠️ header says 170 tests, §13 says 52 — actual 171 |
| `.claude/rules/*` | ✅ Accurate, still applicable |
| API documentation | ❌ None; `routes/api.php` undocumented |
| Owner/operations docs | ❌ None (domain doesn't exist) |

## Documentation that must be updated

1. **`README.md`** — remove "only the reusable foundation" and "no automated
   tests"; both are now false and actively misleading
2. **`README.md`** — fix the setup instructions to warn about `.env.example`
3. **`docs/rental-flow-fa.md` §13** — correct 52 → 171
4. **New** — document that `setup.sh` / `install.bat` / `setup-xampp.bat` are
   Store scripts and must not be run
5. **New** — API documentation
6. **New** — operations runbook (once those domains exist)

---

# 31. Store Separation Audit

**Verified separation [VERIFIED]:** separate application, separate database
(`gamepek_rental`, 54 tables) from the Store (`gamepek`, 45 tables — untouched
throughout this audit), separate config, no shared package, no cross-imports.

## Store leftovers found — to isolate later, NOT removed now

| Leftover | Location | Risk |
|---|---|---|
| **Store DB name** | `.env.example` `DB_DATABASE=gamepek` | **HIGH** |
| **Store setup scripts** | `setup.sh`, `install.bat`, `setup-xampp.bat` — create `gamepek`, hardcode the Store path | **HIGH** |
| Store package identity | `composer.json`: `"name": "gamepek/gamepek-backend"`, description "PlayStation E-Commerce Backend" | LOW |
| **Pruned relations still referenced** | `Admin\UserController` + `admin/users/show.blade.php`: `wishlists`, `reviews`, `questions` | **HIGH — causes the 500** |
| Digital-code labels | `app/helpers.php` `digital_code` block | LOW (dead) |
| GTA VI references | banner admin create/edit placeholders | LOW |
| Unregistered gateways | `.env.example`: `ZARINPAL_MERCHANT_ID`, `IDPAY_API_KEY` | LOW |
| Vite variable | `.env.example`: `VITE_APP_NAME` (no Vite here) | LOW |
| Docker names | `gamepek_app`, `gamepek_net` — collide with Store stack | LOW–MEDIUM |
| `CACHE_PREFIX=gamepek_` | `.env.example` | LOW (separate DBs) |

**No Store-only *features* (wishlist, reviews, questions, digital codes, blog,
gift cards) were reintroduced** — only dangling references to their removed
relations.

**Recommendation: keep the two projects separate.** No architectural reason to
merge was found; the Store is live and the fork exists specifically to protect it.

---

# 32. Business Decisions Required

**All marked REQUIRES BUSINESS OWNER DECISION. None decided here.**

## Blocking the architecture

| # | Decision |
|---|---|
| **BD-1** | **GamePek-owned fleet vs third-party owner marketplace.** Everything in §10 depends on this. |
| **BD-2** | **One `Product` = one physical device, or a pool of units?** |
| **BD-3** | Does the catalog entity become Device + DeviceUnit? |
| **BD-4** | Must identity/bank verification precede reservation and payment, or follow? (Directly governs the CRITICAL finding.) |

## The existing B-register (B1–B14, all still open)

B1 required KYC checks · B2 liveness/face thresholds · B3 attempt cap ·
B4 deposit hold/release · B5 guarantee amount formula · B6 mandatory cheque
inquiries · B7 risk thresholds · B8 final-approval criteria · B9 cancellation/
refund policy · B10 reservation hold expiry · B11 media retention per kind ·
B12 official contract text · B13 SMS templates · B14 post-approval triggers ·
plus: does the calendar block at reservation or at payment, and are the duration
discount tiers real?

## Operational rules with no code and no policy

| Area | Undecided |
|---|---|
| Cancellation | Customer / owner / GamePek — who, when, what refund |
| Late return | Grace period, fee, escalation |
| Damage | Assessment method, liability split, evidence standard |
| Missing accessory / lost device | Valuation, guarantee usage |
| Deposit | When held, when released, what it covers |
| Guarantee enforcement | When and how a cheque is actually used |
| Settlement | Owner payout formula, GamePek commission, timing |
| Dispute | Process, arbiter, evidence |
| Suspension / reputation | Whether penalties exist at all |
| Inspection standard | What "acceptable condition" means |
| Delivery | In-house, courier, customer pickup |

---

# 33. Legal Review Required

**All marked REQUIRES LEGAL REVIEW. No legal rule invented.**

| # | Item |
|---|---|
| **L1** | **Contract legal validity.** The template is a placeholder (B12) and the code explicitly disclaims the signature's legal weight. |
| **L2** | **Electronic signature standing.** Internal HMAC + SMS OTP; not PKI, no eIDAS-equivalent. Is this enforceable in Iran? |
| **L3** | **Minors.** No age gate exists; a minor can currently complete the entire flow including a contract and a cheque guarantee. Enforceability and permissibility both unresolved. |
| **L4** | **Guardian/parent consent** if minors are permitted. |
| **L5** | **Consent capture.** A `consents` table exists but is **empty** — no consent is recorded before collecting national ID, bank details or a cheque. |
| **L6** | **Legal basis for inquiries.** `legal.permission_reference` is null for every provider. |
| **L7** | **Data retention** — identity, bank, cheque, and especially video (B11 all null). |
| **L8** | **Video retention and consent** for liveness/handover/return recordings. |
| **L9** | **Cheque and promissory note handling** — lawful collection, storage, enforcement. |
| **L10** | **Deposit legality** — holding customer funds. |
| **L11** | **Liability allocation** — device damage, loss, injury; GamePek vs owner vs customer. |
| **L12** | **Privacy policy and terms** — Terms are hardcoded placeholder Blade; no privacy policy page exists. |
| **L13** | **SMS marketing/transactional consent.** |
| **L14** | **Receipt / legal evidence standard** for handover and return. |
| **L15** | **Owner payout** — tax, invoicing, withholding obligations. |

---

# 34. Architectural Dependencies

**Nothing in Phases 2–7 can be designed until BD-1 and BD-2 are answered.**

```
BD-1 (owner model?) ─┬─> Owner domain ─> Owner KYC ─> Owner payout
                     │
                     └─> BD-2 (unit vs pool?) ─> Device ─> DeviceUnit
                                                    │
                                                    ├─> Availability (real, per unit)
                                                    │      │
                                                    │      └─> Reservation lifecycle
                                                    │             │
                                                    │             ├─> Hold expiry (B10)
                                                    │             └─> Release on cancel/reject
                                                    │
                                                    └─> Custody chain
                                                           │
                                                           ├─> Pickup ─> Inspection#1
                                                           ├─> Delivery ─> Inspection#2
                                                           ├─> Return ─> Inspection#3
                                                           └─> Owner return ─> Inspection#4
                                                                  │
                                                                  └─> Damage ─> Settlement
                                                                         │
                                                                         ├─> Wallet ledger
                                                                         └─> Owner payout
```

**Independent of the above — can proceed now:**
fixing the 500; releasing reservations on reject/cancel; unifying availability
sources; moving the conflict audit outside the transaction; design system;
SEO; accessibility; `.env.example`; test gaps.

**Must NOT be built before its dependency:**
any owner UI (needs BD-1); any inspection UI (needs custody model); wallet
top-up (needs ledger + real gateway); settlement (needs damage policy);
notifications (needs approved copy, B13).

---

# 35. Master Remaining Work

Each item: problem · why it matters · area · dependencies · priority ·
complexity · risk · business decision? · legal review?

## PHASE 0 — Documentation / Safety

| # | Item | Pri | Cx | BD | Legal |
|---|---|---|---|---|---|
| 0.1 | Fix `.env.example` `DB_DATABASE=gamepek` → `gamepek_rental`; add missing rental vars. *Prevents pointing Rental at the live Store DB.* | **P0** | S | – | – |
| 0.2 | Neutralise or clearly quarantine `setup.sh`, `install.bat`, `setup-xampp.bat`. *They target the Store directory and DB, which exist on the dev machine.* | **P0** | S | – | – |
| 0.3 | Update `README.md` — it claims no rental logic and no tests; both false. | **P0** | S | – | – |
| 0.4 | Correct `docs/rental-flow-fa.md` §13 test count. | P2 | S | – | – |
| 0.5 | Write API documentation. | P2 | M | – | – |

## PHASE 1 — Core Rental Architecture

| # | Item | Pri | Cx | BD | Legal |
|---|---|---|---|---|---|
| 1.1 | **Decide BD-1 (owner model) and BD-2 (unit vs pool).** *Blocks all of Phases 2–7.* | **P0** | – | **YES** | – |
| 1.2 | **Introduce step gating before reserve/pay** per BD-4. *Today money moves with no identity — verified.* | **P0** | M | **YES** | – |
| 1.3 | **Unify availability onto `rental_reservations`**; retire the static `_rental.blocked` calendar. *Customers are misinformed today.* | **P0** | M | – | – |
| 1.4 | **Release reservations on reject/cancel/failure.** *Permanent inventory leak, verified.* | **P0** | M | partial (B9) | – |
| 1.5 | Move the `reservation.conflict` audit outside the transaction. *Audit rows currently roll back.* | **P1** | S | – | – |
| 1.6 | Implement reservation lifecycle transitions (`held→awaiting_payment→paid→active…`). | **P1** | M | B10 | – |
| 1.7 | Reservation hold expiry sweep. | P1 | M | **B10** | – |
| 1.8 | Customer cancellation path. | P1 | M | **B9** | **YES** |
| 1.9 | Limit concurrent draft applications per user. | P3 | S | – | – |

## PHASE 2 — Owner / Device Infrastructure  *(all blocked on 1.1)*

| # | Item | Pri | Cx |
|---|---|---|---|
| 2.1 | Owner role, account, owner-scoped KYC | **P1** | L |
| 2.2 | `devices` entity — brand, model, serial, condition, accessories | **P1** | L |
| 2.3 | `device_units` — the bookable physical instance | **P1** | L |
| 2.4 | Device → Owner ownership link | **P1** | M |
| 2.5 | Owner device registration flow + media upload | **P1** | L |
| 2.6 | Admin device review/approval workflow | **P1** | M |
| 2.7 | Migrate reservations from `product_id` to `device_unit_id` | **P1** | L |
| 2.8 | Owner payout details (bank, tax) | P1 | M |

## PHASE 3 — Reservation & Availability

3.1 Owner-declared availability windows (P1/L) · 3.2 Maintenance/blackout holds
(P1/M) · 3.3 Per-unit overlap logic replacing per-product lock (P1/L) ·
3.4 `Product::isInStock()` → interval delegation (P1/M) · 3.5 Catalog facets
(currently empty) (P2/M) · 3.6 Multi-city support incl. `StoreAddressRequest`
(P2/M, **BD**)

## PHASE 4 — GamePek Operations

4.1 Operations dashboard (P1/L) · 4.2 Reservation management screens (P1/M) ·
4.3 Custody/chain-of-possession model (P1/L) · 4.4 Operational task queue
(P1/L) · 4.5 Courier/logistics integration (P2/L, **BD**)

## PHASE 5 — Pickup & Physical Inspection

5.1 `inspections` entity, 4 inspection points (P1/L) · 5.2 Structured checklist:
serial, model, accessories, ports, HDMI, controller, power, storage, system,
gameplay (P1/L, **BD** on standard) · 5.3 Photo/video capture + private storage
(P1/L, **Legal** on retention) · 5.4 Pickup receipt + owner signature (P1/M,
**Legal**) · 5.5 Device Received state + audit (P1/M) · 5.6 Inspection
approve/reject workflow (P1/M, **BD**)

## PHASE 6 — Delivery & Return

6.1 Delivery scheduling + proof (P1/L) · 6.2 Delivery inspection + customer
acceptance (P1/M, **Legal**) · 6.3 Active rental state + trigger (P1/M, **B14**)
· 6.4 Return scheduling (P1/L) · 6.5 Return inspection + customer-reported
damage (P1/L) · 6.6 Owner return + final receipt (P1/M)

## PHASE 7 — Damage / Cancellation / Refund / Settlement

7.1 Damage assessment model + evidence (P1/L, **BD+Legal**) · 7.2 Late return
handling (P1/M, **BD**) · 7.3 Missing accessory / lost device (P1/M, **BD**) ·
7.4 Deposit hold/release (P1/M, **B4, Legal**) · 7.5 Guarantee enforcement
(P1/L, **B6, Legal**) · 7.6 Refund workflow (P1/M, **B9**) · 7.7 Settlement
engine + owner payout (P1/L, **BD+Legal**) · 7.8 Dispute resolution (P2/L,
**BD+Legal**)

## PHASE 8 — KYC / Bank / Guarantee / Contracts

8.1 Select and wire real identity provider (**P1**/L, **BD**) · 8.2 Real bank
ownership provider (**P1**/M, **BD**) · 8.3 Real Sayad provider (**P1**/M,
**BD**) · 8.4 Define B1/B2/B3 (**P1**, **BD**) · 8.5 Liveness/face capture UI
(P1/L, **Legal**) · 8.6 **Consent capture before any PII collection**
(**P1**/M, **Legal**) · 8.7 **Age gate / minors policy** (**P1**/M, **Legal**) ·
8.8 Legal contract text + review (**P1**/L, **Legal**) · 8.9 Contract template
admin UI + sanitisation (P2/M) · 8.10 Enable Sayad checksum once spec confirmed
(P2/S) · 8.11 Media retention purge policy (P1/M, **B11, Legal**)

## PHASE 9 — Payment

9.1 Obtain rental merchant credentials (**P1**, **BD**) · 9.2 Wire live gateway
+ sandbox validation (**P1**/M) · 9.3 Refund implementation (P1/M, **B9**) ·
9.4 Reconciliation scheduling + alerting (P1/M) · 9.5 Deposit hold mechanism
(P1/L, **B4, Legal**) · 9.6 Payment failure/timeout UX (P2/M)

## PHASE 10 — Wallet

10.1 **Decide whether a wallet exists at all** (**P0 decision**, **BD**) ·
10.2 Balance column + double-entry ledger (P2/L) · 10.3 Top-up via gateway
(P2/M) · 10.4 Withdrawal + manual approval (P2/L, **BD+Legal**) · 10.5 Admin
wallet management (P2/M) · 10.6 **Remove or clearly label the mock UI** — it
currently presents fake money as real (**P1**/S)

## PHASE 11 — SMS / Notifications

11.1 Approve SMS copy (**P1**, **B13**, **BD**) · 11.2 Select SMS provider
(**P1**, **BD**) · 11.3 Wire state→template mapping (P1/M) · 11.4 Delivery
status + retry (P1/M) · 11.5 Owner notifications (P1/M) · 11.6 Operational
notifications (P1/M) · 11.7 Notification preferences + opt-out (P2/M, **Legal**)

## PHASE 12 — Customer UX

12.1 Fix product-page calendar (**P0**, = 1.3) · 12.2 Loading/skeleton states
(P1/M) · 12.3 Form labels everywhere (**P1**/M) · 12.4 Empty states (P1/M) ·
12.5 Error states (P1/M) · 12.6 Progressive enhancement for login (P2/M) ·
12.7 Application progress clarity (P2/M) · 12.8 Trust/transparency surfaces
(P1/M)

## PHASE 13 — Owner UX  *(blocked on Phase 2)*

13.1 Owner dashboard · 13.2 Device registration wizard · 13.3 Availability
calendar · 13.4 Verification status · 13.5 Reservation notifications ·
13.6 Income/settlement views · 13.7 Receipts. All **P1/L**.

## PHASE 14 — Admin UX

14.1 **Fix `/admin/users/{id}` 500** (**P0**/S) · 14.2 Operations screens for
every Phase 4–7 workflow (P1/XL) · 14.3 Reservation management (P1/M) ·
14.4 Owner management (P1/L) · 14.5 Device/unit management (P1/L) ·
14.6 Settlement/payout screens (P1/L) · 14.7 Notification management (P2/M) ·
14.8 Bulk operations (P3/M)

## PHASE 15 — Design System

15.1 Button component — collapse 85 variants (**P1**/M) · 15.2 Input/form
components (P1/M) · 15.3 Radius + spacing + elevation scales (P1/S) ·
15.4 Status/badge system (P1/M) · 15.5 Loading/skeleton primitives (P1/M) ·
15.6 Empty/error state components (P1/M) · 15.7 Table + pagination components
(P2/M) · 15.8 Document the system (P2/M)

## PHASE 16 — Mobile / Responsive

16.1 **Full visual QA at breakpoints — currently unverified** (**P1**/M) ·
16.2 Touch target audit (P1/S) · 16.3 Mobile calendar UX (P1/M) · 16.4 Admin
tables on mobile (P2/M) · 16.5 Mobile form UX (P2/M)

## PHASE 17 — SEO

17.1 `sitemap.xml` (P2/S) · 17.2 Canonical URLs (P2/S) · 17.3 Open Graph
(P2/S) · 17.4 JSON-LD Product/Offer/Breadcrumb/Organization (P2/M) ·
17.5 Fix duplicate `<h1>` (P2/S) · 17.6 Image alt coverage (P2/M) ·
17.7 Breadcrumbs (P2/M) · 17.8 **Replace Tailwind CDN with a build** — CWV +
Iran availability (**P1**/M)

## PHASE 18 — Security

18.1 Step gating (= 1.2) (**P0**) · 18.2 Conflict audit fix (= 1.5) (**P1**) ·
18.3 `.env.example` (= 0.1) (**P0**) · 18.4 Store scripts (= 0.2) (**P0**) ·
18.5 Production config hardening — `APP_DEBUG=false`, error pages (**P1**/S) ·
18.6 Add FK on `verification_media.rental_application_id` (P2/S) ·
18.7 Contract template sanitisation before any editor ships (P2/M) ·
18.8 Security review of owner/settlement domains once built (P1/L) ·
18.9 Secrets management for production (P1/M)

## PHASE 19 — Performance

19.1 Tailwind build (= 17.8) (**P1**) · 19.2 Self-host fonts (**P1**/S — Iran) ·
19.3 Catalog/search caching (P2/M) · 19.4 Load-test availability at volume
(P1/M) · 19.5 Queue provider calls (P1/M) · 19.6 Image optimisation (P2/M) ·
19.7 Admin table pagination review (P2/M)

## PHASE 20 — Testing / QA

20.1 Concurrent reservation test (**P1**/S) · 20.2 Reservation release test
(**P1**/S) · 20.3 Availability consistency test (**P1**/S) · 20.4 Step gating
test (**P1**/S) · 20.5 Conflict audit persistence test (**P1**/S) ·
20.6 `/admin/users/{id}` smoke test (**P1**/S) · 20.7 Browser/E2E suite
(P1/L — **none exist**) · 20.8 Visual regression (P2/L) · 20.9 Tests for every
new domain (P1/XL) · 20.10 Accessibility tests (P2/M)

## PHASE 21 — Monitoring / Operations

21.1 Error tracking (P1/M) · 21.2 Uptime/health checks (P1/S) · 21.3 Audit log
monitoring + alerts (P1/M) · 21.4 Payment reconciliation alerting (P1/M) ·
21.5 Backup + restore procedure (**P1**/M) · 21.6 Log retention (P2/M, **Legal**)

## PHASE 22 — Production Deployment

22.1 Production environment provisioning (P1/L) · 22.2 Deployment pipeline
(P1/M) · 22.3 Storage path verification on the real host (P1/M) ·
22.4 SSL/domain (P1/S) · 22.5 Store user-data import (P1/L, **BD**) ·
22.6 Rollback procedure (P1/M)

## PHASE 23 — Launch Readiness

23.1 Legal sign-off on all L1–L15 (**P0 for launch**, **Legal**) ·
23.2 Business sign-off on all B/BD items (**P0 for launch**, **BD**) ·
23.3 Operational staff training + runbook (P1/L) · 23.4 Support process
(P1/M) · 23.5 Pilot with real devices (P1/L) · 23.6 Launch checklist (P1/M)

---

# 36. P0 / P1 / P2 / P3 Priorities

## P0 — BLOCKER (before serious development continues)

1. **BD-1 / BD-2** — owner model and unit-vs-pool. Everything downstream waits.
2. **Step gating (1.2)** — payment with no identity, verified live.
3. **Unify availability (1.3)** — customers are actively misinformed.
4. **Release reservations (1.4)** — permanent inventory leak, verified.
5. **`.env.example` (0.1)** — Store DB pointer.
6. **Store setup scripts (0.2)** — they target the live Store.
7. **`/admin/users/{id}` 500 (14.1)** — trivially fixable, currently broken.
8. **README (0.3)** — actively misleads anyone onboarding.

## P1 — CRITICAL (before production)

Conflict audit fix · reservation lifecycle · real KYC/bank/cheque providers ·
live payment gateway · consent capture · age gate/minors · legal contract text ·
SMS copy + provider · Tailwind build + self-hosted fonts · form labels ·
loading/error/empty states · button/design components · the six missing tests ·
error tracking + backups · production hardening · **the entire Owner and
Operations domains (Phases 2, 4–7)**.

## P2 — IMPORTANT (before launch)

Wallet backend (if BD says wallet exists) · SEO package · admin bulk ops ·
notification preferences · dispute resolution · contract template UI · caching ·
image optimisation · accessibility tests · browser E2E.

## P3 — POLISH

Draft application limits · admin table refinements · visual regression ·
advanced reporting · reputation/penalties.

## DO NOT BUILD YET

| Item | Why |
|---|---|
| **Any owner-facing UI** | BD-1 undecided — you would build the wrong model |
| **Device/unit schema** | BD-2 undecided |
| **Inspection UI** | Custody model and inspection standard undefined |
| **Settlement/payout** | Damage, commission and liability policy undefined |
| **Wallet backend** | Not decided whether a wallet exists; no ledger design |
| **Deposit hold** | B4 undefined; legal question on holding funds |
| **Guarantee enforcement** | B6 undefined; legal process unknown |
| **Any SMS** | B13 — no approved copy; sending unapproved copy to customers is a real risk |
| **Post-approval transitions** | B14 — triggers undefined by design |
| **Cancellation/refund logic** | B9 undefined |
| **Catalog facets** | Rental taxonomy not designed |
| **Multi-city** | Delivery capability not established |

---

# 37. Design Scorecard

Scores marked ⚠️ are constrained by the absence of visual tooling.

| Dimension | Score | Basis |
|---|---|---|
| Visual Design | ⚠️ **[UNKNOWN]** | Cannot render. Markup suggests a competent, consistent Tailwind aesthetic inherited from a live product. |
| Brand Consistency | **7/10** | Single token file, one palette, one card component, one search bar — genuinely enforced. |
| UX | **4/10** | Core journey works, but no loading states, no labels, fake calendar data, fake wallet. |
| Customer Journey | **5/10** | Complete to `Approved`; then a dead end. |
| Owner Journey | **0/10** | Does not exist. |
| Admin UX | **4/10** | Application review is good; ~60% of operations missing; one page 500s. |
| Mobile UX | ⚠️ **[UNKNOWN]** | Responsive classes and a mobile nav exist; unverified at breakpoints. |
| RTL / Persian UX | **8/10** | Best-executed dimension: correct `dir`, Jalali with real leap rules, Persian digits, `dir="ltr"` on mixed fields, Persian errors throughout. |
| Accessibility | **2/10** | Labels essentially absent; unlabelled icon buttons; 2 `<main>`; no skip link. |
| Trust / Transparency | **3/10** | Fake availability and fake wallet balances in a product built on trust. |
| Information Architecture | **6/10** | Navigation coherent, single-source menu tree with fallback. |
| Design System | **3/10** | 85 button variants, 8 radii — a palette, not a system. |
| Error Handling UX | **6/10** | Backend messaging is excellent Persian; frontend surfacing unverified. |
| Loading UX | **1/10** | No loading states found anywhere. |
| Empty States | ⚠️ **3/10** | Some documented (menu fallback, hidden empty rails); most unverified. |
| **Overall Design Readiness** | **4/10** | Strong foundation, no system, several trust-damaging fictions. |

---

# 38. Production Readiness Score

| Dimension | Score | Evidence |
|---|---|---|
| **Backend** | **5/10** | Excellent chain discipline, 171 passing tests; but no gating, leaking reservations, half the domain missing |
| **Frontend** | **4/10** | Pages render, RTL solid; no loading states, no labels, fake calendar |
| **UX** | **4/10** | See §37 |
| **Admin** | **3/10** | Review works; operations absent; a page 500s |
| **Owner** | **0/10** | Does not exist |
| **Payment** | **2/10** | Architecture excellent; **mock only, no credentials** |
| **KYC** | **2/10** | Architecture excellent; **all providers fake** |
| **Guarantee** | **2/10** | Architecture good; fake provider; no enforcement; no amount rule |
| **Legal** | **1/10** | Contract disclaimed; no consent captured; no age gate; 15 open items |
| **Security** | **4/10** | Strong primitives; one CRITICAL gating flaw; audit rollback; Store-DB hazards |
| **Testing** | **5/10** | 171/171 pass, deep on the chain; zero coverage of the four worst defects; no E2E |
| **Operations** | **1/10** | No monitoring, no backups, no runbook, no operational workflows |
| **PRODUCTION READINESS** | **1.5/10** | Not deployable. No real payment, no real identity, no operations, unresolved legal. |

---

# 39. Recommended Development Order

1. **Decide BD-1 and BD-2.** Nothing structural should be written first.
2. **Safety sweep** — `.env.example`, Store scripts, README, the 500. Days, not weeks.
3. **Fix the four verified defects** — gating, availability unification,
   reservation release, conflict audit — each with the test that locks it.
4. **Design the Owner + Device + DeviceUnit + Custody model on paper**, reviewed
   against BD-1/BD-2 before any code.
5. **Build Owner + Device domain** (Phase 2), migrating reservations onto units.
6. **Build Operations: pickup → inspection → delivery → return** (Phases 4–6),
   with the inspection standard decided first.
7. **Resolve damage/cancellation/settlement policy**, then build Phase 7.
8. **Wire real integrations** — payment, KYC, bank, cheque (Phases 8–9) — once
   vendors and B1–B7 are decided.
9. **Legal pass** — contract, consent, minors, retention (L1–L15).
10. **SMS** once copy is approved.
11. **Design system + accessibility + responsive QA** (Phases 15–16) — can run
    in parallel from step 2 onward.
12. **SEO + performance** (Phases 17, 19).
13. **Testing breadth + E2E** (Phase 20) — continuously, not at the end.
14. **Monitoring, deployment, launch readiness** (Phases 21–23).

**Parallelisable from day one:** design system, accessibility, SEO,
performance, documentation, test breadth. These need no business decision.

---

# 40. Final Conclusion

GamePek Rental is a **well-engineered half of a business**.

The half that exists is genuinely good. The derived-state orchestrator, the
structural idempotency, the fail-closed integration seams, the append-only
audit trail, the sensitive-data pattern, and the honest `TODO(business)`
register are better than typical for a project at this stage. The concurrency
protection is real — I proved it. The tests are real — 171 of them, all passing.
The refusal to invent policy is a discipline worth preserving.

The half that does not exist is the half the business actually sells. There is
no owner, no device, no unit, no custody, no inspection, no delivery, no return,
no damage handling, and no settlement. The intended lifecycle has roughly
fourteen post-approval steps; **three exist as unreachable enum values and
eleven are entirely absent.**

Between those halves sit four defects I verified by running the system, not by
reading it: a customer paid with no identity; a rejected application
permanently removed a device from inventory; the product page told me dates
were free when they were booked and booked when they were free; and five
booking conflicts left no audit trace at all.

None of that makes the foundation wrong. It makes the boundary between
"mechanism built" and "product complete" much wider than the documentation —
and the feature list in the audit brief — suggests. The most valuable thing
this codebase has is that it refuses to guess. The most valuable thing it
needs is for someone to stop it having to: **decide the owner model, and the
rest of the roadmap becomes buildable.**

**Realistic completion against the intended platform: 25–30%.**

---

# TOP 20 THINGS WE SHOULD DO NEXT

Ordered by dependency and importance.

| # | Action | Why now | Pri |
|---|---|---|---|
| 1 | **Decide BD-1: GamePek-owned fleet or third-party owner marketplace** | Blocks the entire supply side. Every Phase 2–7 item depends on it. | P0 |
| 2 | **Decide BD-2: one `Product` = one device or a pool** | Determines the reservation model and whether the current overlap logic is even correct. | P0 |
| 3 | **Fix `.env.example` (`gamepek` → `gamepek_rental`)** | Composer auto-copies it; one careless setup writes to the live Store DB. | P0 |
| 4 | **Quarantine `setup.sh` / `install.bat` / `setup-xampp.bat`** | They create and migrate the Store DB and hardcode the Store path, which exists on the dev machine. | P0 |
| 5 | **Decide BD-4 and add step gating before reserve/pay** | Verified: a customer paid 1,125,000 T with `identity: NONE`. | P0 |
| 6 | **Unify availability onto `rental_reservations`; kill the static calendar** | Verified: the product page tells customers the opposite of the truth in both directions. | P0 |
| 7 | **Release reservations on reject/cancel/payment-failure** | Verified: a rejected application permanently removed a device from search. | P0 |
| 8 | **Fix `/admin/users/{id}` (remove 3 pruned relations)** | 500s for every user; a one-line fix. | P0 |
| 9 | **Update `README.md`** | Claims no rental logic and no tests; both demonstrably false. | P0 |
| 10 | **Move the `reservation.conflict` audit outside its transaction** | 5 conflicts produced 0 audit rows — availability probing is invisible. | P1 |
| 11 | **Add the six regression tests for defects 5–8, 10** | Each defect exists precisely because nothing tested that seam. | P1 |
| 12 | **Design Owner + Device + DeviceUnit + Custody on paper** | Do not write schema before this is reviewed against BD-1/BD-2. | P1 |
| 13 | **Decide the inspection standard** (what is checked, what evidence, what "acceptable" means) | Gates Phases 5–7 entirely. | P1 |
| 14 | **Decide B9 (cancellation/refund) and B4 (deposit)** | Needed before any money can move in either direction. | P1 |
| 15 | **Select vendors for identity, bank, cheque, SMS, payment** | Every integration is a fake today; procurement lead time is real. | P1 |
| 16 | **Legal review: contract validity, minors, consent, retention** | A minor can currently sign a contract and submit a cheque. No consent is recorded anywhere. | P1 |
| 17 | **Add consent capture before any PII collection** | `consents` table exists and is empty; PII is already being collected. | P1 |
| 18 | **Replace Tailwind CDN with a build; self-host fonts** | Browser-compiled CSS plus Google Fonts is a real availability and CWV problem for Iranian hosting. | P1 |
| 19 | **Build the button/input/loading/empty component layer** | 85 primary-button variants; no loading states; blocks consistent UI for every new screen. | P1 |
| 20 | **Do a real visual + responsive QA pass with browser tooling** | This audit could not render a single page; visual quality is genuinely unverified. | P1 |

---

*End of audit. Read-only: no source, schema, migration or UI was modified;
no commit was created.*
