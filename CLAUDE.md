# CLAUDE.md

Guidance for Claude Code (claude.ai/code) working in this repository.

This file is the **operational contract**: current state, hard rules, and
known hazards. The deep technical reference is
[`docs/rental-flow-fa.md`](docs/rental-flow-fa.md) (Persian, 762 lines) —
flow maps, design rationale, documented bug fixes, RTL conventions, the test
matrix and a manual test scenario. Path-scoped rules live in
`.claude/rules/`. Setup lives in `README.md`.

> **Last verified against the code:** 2026-09-08, at commit `554befa`.
> If you find this file disagreeing with the code, the **code wins** — and
> say so rather than coding to this document.

---

## 1. Project overview

- **GamePek Rental** — renting game consoles and accessories. The second
  business area of GamePek. The Store (`gamepek.com` / `gamepek-backend`) is
  a **separate application with a separate database**.
- **Laravel 11 (`v11.54.0`) / PHP 8.2.** MySQL/MariaDB — *not* SQLite.
- **Persian, RTL.** All user-facing copy, errors and success messages are
  clear Persian.
- **No build step.** Tailwind (play CDN), Vazirmatn (Google Fonts) and Font
  Awesome load from CDN; all JavaScript is inline in Blade. There is no
  `resources/js`, no `package.json`, no Vite.
- **Three production dependencies:** `laravel/framework`, `laravel/tinker`,
  `spatie/laravel-permission`.
- **No repository layer.** Controllers → Services → Eloquent.

### Implementation status — read this before assuming anything

A complete, audited, state-machine-driven **rental chain exists and works**:
reservations, identity/bank/guarantee verification, payment, contract
generation, signing, and admin approval are all implemented, and the
repository has a **test suite of 171 test methods**.

| Area | Status |
|---|---|
| Rental catalog, pricing, Jalali calendar, date-range search | Implemented |
| Reservation creation (locked, overlap-checked, price-snapshotted) | Implemented |
| Rental application chain + transitions ledger + audit trail | Implemented |
| Payment via mock gateway | Implemented |
| Identity / bank / guarantee verification | Mechanism implemented; **fake providers only; policy undefined** |
| Contract generation, acceptance, OTP signing | Mechanism implemented; **text has no legal validity** |
| Admin verification / rental-application / audit screens | Implemented |
| Reservation release or expiry | **Not implemented** |
| Post-approval lifecycle | Active, Returned and Closed **implemented** — Active/Returned by delivery/return operations, Closed by the readiness-gated `close()` |
| Owner / lessor domain | Implemented — owners, mixed fleet, serials, admin review |
| Operational task domain | Implemented — owner pickup, customer delivery, customer return, owner return |
| Device custody history (owner -> GamePek) | Implemented — custody is separate from ownership |
| Live payment gateway | Not implemented — no credentials |
| Live KYC / bank / cheque providers | Not implemented — none chosen |
| Rental SMS notifications | Not implemented — no approved copy (B13) |
| Wallet backend (`WalletService`, persisted balance + immutable ledger) | Implemented — nothing calls `credit()`/`debit()` yet |
| Wallet-driven settlement, payout, deposit, refund, damage charges | **Not implemented** |
| Customer profile wallet tab | Still **frontend `localStorage` prototype**, not connected to the real backend |
| Device allocation to a reservation | Manual admin attachment implemented (`attachDevice()`), now with device-level overlap safety — **selection policy itself remains undecided (section 10.3b)** |
| Delivery / customer return / owner return | Implemented (staff-driven), all four custody legs. Inspection: **free-text append-only evidence only**. Damage: **expert amount recorded (append-only), never charged** (docs/operations/OPERATIONS_AND_CUSTODY.md §14–§15) |
| Settlement (35/65) | **Implemented**: rental price only, owner credited once to the Owner Wallet via `WalletService` after the settlement point. **No scheduled daily run** |
| Promissory note / damage payment | **Implemented** (`GuaranteeNoteService`, `rental_damage_payments`); never modelled as money |
| Closure | **Implemented**: `RentalChainOrchestrator::close()`, explicit and gated by `RentalClosureReadiness`; never automatic (docs/operations/OPERATIONS_AND_CUSTODY.md §16) |
| Receipt/signature for a handover | Receipt reference recorded at the door; **whether a digital signature may replace the paper one is undecided** |

The catalog entity is still named `Product`/`products`, and rental facts live
in `products.attributes['_rental']` (JSON) with **no rental columns**.
Renaming is an undecided domain decision (§9).

---

## 2. Commands

```bash
composer install
cp .env.example .env            # already targets gamepek_rental; set DB_USERNAME/PASSWORD
php artisan key:generate        # APP_KEY also keys the OTP HMAC and the contract signature
php artisan migrate             # never migrate:fresh / migrate:refresh / db:wipe
php artisan db:seed             # users, settings, shipping, home sections, menu, rental navigation, contract template
php artisan serve

vendor/bin/pint                 # the only formatter/linter configured
vendor/bin/phpunit              # requires MySQL database `gamepek_rental_test`

php artisan db:seed --class=RentalDemoProductSeeder   # local demo consoles; refuses in production
php artisan payments:reconcile                        # PAY-06
php artisan verification:purge-media                  # skips any kind whose retention is null (B11)
```

**Tests run on MySQL, not SQLite.** Two historical migrations are MySQL-only
(`2024_01_01_100003` uses `fullText()`; `2026_07_12_000004` issues a raw
`ALTER TABLE … ADD CONSTRAINT`) and must not be edited. The test database name
is deliberately distinct from the development one so `RefreshDatabase` can
never truncate real data.

A fresh checkout has **no `vendor/` and no `.env`** and will not boot until
both exist.

---

## 2b. Availability has two axes — do not merge them

`App\Services\Rental\RentalAvailabilityService` is the single authority, and it
answers two different questions that must stay apart:

| Method | Question | Axis |
|---|---|---|
| `isFree()` | is the inventory already committed? | **overlap** |
| `bookingBlockedReason()` | may a customer book this window at all? | **time** |

They are asked at different moments. `isFree()` also runs AFTER a payment
clears, to re-check the range under a lock. If the "not in the past" rule lived
inside it, a payment settling slightly late would find its own start date in the
past and refuse to create the reservation for money already taken. **Never move
the time rules into `isFree()`.**

Ranges are **inclusive on both ends** (`end = start + days - 1`), everywhere:
overlap, the calendar, the search window and its displayed day count. A one-day
rental occupies only its start date. Dates are civil dates in `Asia/Tehran`,
never timestamps — availability is date-based (C-03), never hourly.

The overlap predicate exists once, in `RentalReservation::scopeOverlapping()`.
A test asserts no second copy appears in `app/` or `resources/views/`. The UI
may display availability; it must never compute it.

**There is no owner availability calendar.** C-16 confirms owners choose their
dates, but no table, model, service, route or screen exists, and four policy
questions block building one — see `docs/business/CONFIRMED_DECISIONS.md` §4.1.
Availability is derived from `rental_reservations` alone.

---

## 3. Rental architecture — the central principle

`RentalApplication` is the aggregate root. Its `state` column is **derived,
never commanded**.

**`App\Services\Rental\RentalChainOrchestrator` is the ONLY writer of
`rental_applications.state`.** If you find an assignment to `state` anywhere
else, that is the bug — fix the caller, do not add a second writer.

- Every step service mutates **only its own child record** (identity, bank
  account, reservation, order, guarantee, contract) and then calls
  `advance()`.
- `nextState()` is a **pure predicate ladder** with no side effects: it reads
  persisted child state top-down and returns the highest rung whose facts
  hold. Because it reads only stored data, a re-run after a crash lands on
  the same answer.
- `advance()` locks the row (`lockForUpdate`), derives, and **if nothing
  changed writes nothing** — no transition row, no audit row. Idempotency is
  structural, not defensive. Keep it that way.
- `state` is **not fillable**. No request field, hidden input or route
  parameter can write it.

Three edges are **not derived**, because they are human decisions:
`approve()`, `reject()`, `cancel()`. They are guarded by
`RentalApplicationState::canTransitionTo()`.

`Approved` is specially protected: `nextState()` short-circuits on it, so a
customer merely reloading their application page cannot re-derive
`AwaitingFinalApproval` and silently revoke an admin's approval. `Approved`
is written only by `approve()`, and only after the signature is present, the
contract text is still intact (`isIntact()`) and the signature verifies.

### The chain

```
Draft
 └─ IdentityPending → IdentityVerified
     └─ BankPending → BankVerified
         └─ ReservationHeld
             └─ PaymentPending → Paid
                 └─ GuaranteePending → GuaranteeVerified
                     └─ ContractGenerated → ContractAccepted → ContractSigned
                         └─ AwaitingFinalApproval → Approved
                             └─ Active → Returned → Closed   ← B14, partly decided
```

`transitionPostApproval()` is the single attachment point for the last three
states, and it is still the ONLY thing that writes them.

- **Approved → Active** fires when a `customer_delivery` operation completes,
  and nothing else does it. CONFIRMED: a rental starts when GamePek
  physically hands the device to the customer — **not** when the start date
  arrives.
- **Active → Returned** fires when a `customer_return` operation completes.
  The return is arranged through support; there is no customer-facing
  control that starts one.
- **Returned** is stable: `nextState()` never re-derives Approved, Active or
  Returned, so `advance()` cannot move a post-approval rental backwards. The
  `owner_return` operation (GamePek → owner) changes no application state.
- **Returned → Closed** is still refused: `config('rental.lifecycle.closure_trigger')`
  is `null`, so it records `rental_application.policy_undefined`. Closure
  waits on deposit release (B4), damage assessment and media retention (B11).

`nextState()` still derives none of these, and **no route posts a state** —
the two staff routes that exist open an *operation*, whose completion asks
the orchestrator. See `docs/operations/OPERATIONS_AND_CUSTODY.md` §13.

Related invariants worth preserving:

- Money and totals are **always** computed server-side.
  `RentalPricingService::quote()` returns an immutable `RentalQuote` and is
  authoritative. `partials/rental-panel.blade.php` mirrors the arithmetic in
  JS for **live preview only**. Never accept a price, discount or total from
  the frontend.
- `deposit` is **never** part of `payableNow`. The order total is
  `payable_now`. **The deposit is never charged** — how it is held is B4.
- `App\Support\Rental\RentalItem` is the **single** reader of
  `attributes['_rental']`. Never index into that blob anywhere else.
- `CartService::addItem()` **rejects** rentable products with a Persian
  error — a rental cannot go through the buy flow. Guarded once in the
  service, not per view.
- `OrderService::markAsPaid()` is idempotent. Keep it that way.
- Reservations are created under a product row lock with an overlap check
  against `rental_reservations` (`blocking()` scope).
- **`App\Services\Wallet\WalletService` is the only writer of
  `wallets.balance`.** Same discipline as `RentalChainOrchestrator`:
  `credit()`/`debit()` run inside `lockForUpdate()` + `DB::transaction()`,
  writing the balance and its explaining `WalletTransaction` ledger row
  together or not at all. `wallet_transactions` is append-only — no
  `updated_at`, and the model overrides `save()`/`update()`/`delete()` to
  throw once a row exists, so history cannot be edited even by a future
  mistake. An optional `idempotency_key`, unique per wallet at the database
  level, makes a retried `credit()`/`debit()` call a no-op instead of a
  double movement. **No settlement, owner payout, deposit, refund or
  damage-charge policy is implemented or invented here** — nothing in the
  codebase calls `credit()`/`debit()` yet; each future caller decides its
  own trigger and reason when it is built. The customer profile's wallet
  tab remains a separate, unconnected `localStorage` prototype (§9.12).

---

## 4. Fail-closed rules — never weaken these

This codebase's core safety property is that **nothing ever appears to
succeed because it was not configured**. Every one of these is deliberate.
Do not relax any of them to make a flow work, pass a test, or unblock a demo.

- **An unconfigured provider THROWS.** A driver with no registered adapter
  resolves to the `Unconfigured*` provider, which raises. It never silently
  falls back to a fake. (`IntegrationServiceProvider`)
- **`fake` drivers are refused outside `local|testing`.** A fake must never
  answer a real customer's verification.
- **The mock payment gateway is structurally unresolvable in production.**
  `GatewayRegistry` hands out `MockGateway` only in `local|testing`, so a
  `PAYMENT_GATEWAY` typo cannot fall through to mock. `MockPaymentController`
  additionally 404s outside those environments **and** checks transaction
  ownership. Both guards stay.
- **Undefined business policy refuses and audits.** When a required policy
  value is null/empty, the code records `*.policy_undefined` and stops. It
  never guesses.
- **`verify()` is the only source of "paid".** `GatewayCallback` has no
  `success` field by design — the callback is parsed, never trusted.
  `PaymentService::settle()` locks, returns the stored result if already
  successful, treats `Unknown` as still-pending for reconciliation, and fails
  on amount mismatch.
- Do not implement real payment gateway behaviour until the owner confirms
  rental-specific merchant credentials.
- Do not implement real SMS behaviour until the owner confirms a provider.

---

## 5. Business decisions — B1–B14, all UNDECIDED

Each is marked in code with `TODO(business)` beside the decision point, has a
`null`/empty default, and audits `*.policy_undefined` when reached.

| Code | Undecided decision |
|---|---|
| B1 | Which checks are mandatory for KYC level 2 |
| B2 | Liveness / face-match score threshold |
| B3 | Inquiry attempt cap |
| B4 | Deposit policy — when held, when released |
| B5 | Guarantee amount formula |
| B6 | Which of CHEQUE-01..07 are mandatory |
| B7 | Credit-risk rejection thresholds |
| B8 | Final-approval criteria (automatic or manual? which role?) |
| B9 | Cancellation / refund policy |
| B10 | Reservation hold expiry duration |
| B11 | Media retention period, per kind |
| B12 | Official contract text — the current template has **no legal validity** |
| B13 | Rental-event SMS templates |
| B14 | Post-approval triggers — **Active and Returned decided** (delivery/return completion); **Closed still undecided** |
| — | Whether the calendar blocks at reservation time or at payment time |
| — | Duration discount tiers in `config('rental.pricing.duration_discounts')` are a **placeholder**, not owner-approved pricing |

**The rules, without exception:**

- **NEVER invent business policy.** Not a threshold, not a fee, not a
  retention period, not a refund percentage, not contract wording, not an SMS
  body, not a legal basis, not an API endpoint, not a tariff.
- **NEVER auto-approve.** Not an identity, not a guarantee, not an
  application.
- **NEVER auto-delete.** Media with a null retention is skipped, not purged.
- **NEVER auto-send.** No SMS fires for an unknown template key.
- If a rule is needed and not defined, **stop and ask**. Say
  "UNDECIDED — requires owner decision" rather than picking a default.

---

## 6. Security rules

- **Sensitive data pattern — encrypted + HMAC hash + mask.** National code,
  PAN, IBAN and Sayad id are stored as `*_encrypted` (encrypted),
  `*_hash` (keyed HMAC, used for lookup and uniqueness **without
  decryption**) and `*_mask` (the only form rendered). **The raw value never
  reaches a Blade template.** Follow this pattern for any new sensitive
  field.
- **OTP codes are stored as a keyed hash only** — plaintext is never
  persisted. This applies to both login OTP (`otp_codes`) and contract
  signing OTP (`contract_signature_otps`).
- **The signing OTP is a separate table from the login OTP and must stay
  separate.** It is bound to that contract and that signer, is one-time, and
  has its own expiry and attempt cap. **A login OTP must never be able to
  sign a contract.**
- **Audit redaction.** `AuditLogger` strips these keys at **any depth**:
  `national_code`, `pan`, `card_number`, `iban`, `sheba`, `sayad_id`, `otp`,
  `code`, `password`, `secret`, `token`, `api_key`. Add to this list when you
  add a sensitive field. An audit-write failure is logged, never surfaced to
  the user.
- **`audit_events` is append-only** (no `updated_at`), as is
  `rental_application_transitions`. Never update or delete a row in either.
- **`AssignCorrelationId`** mints a UUID per request so every row from one
  request is traceable. Preserve it.
- **Verification media stays private.** It lives on the private
  `verification` disk — **never** the `public` disk, which is a real
  Apache-served directory and therefore reachable by URL. It is served only
  via `URL::temporarySignedRoute` + an explicit ownership check +
  `Cache-Control: private, no-store`, and **every read writes an audit row**.
  A purge keeps the row (`state = purged`, `path` nulled) so the trail of it
  having existed survives.
- **Ownership checks are explicit in controllers**, not delegated to
  route-model binding alone. Required for every user-specific resource.
- **Contract rendering uses `strtr` over `e()`-escaped values — never
  `Blade::render()`.** The template is admin-editable and must not become an
  RCE path. The rendered text is snapshotted with a sha256 `content_hash`, so
  a signed contract no longer depends on the template.
- The internal signature is
  `hash_hmac('sha256', contentHash|userId|signedAt, APP_KEY)`. It proves
  integrity and phone possession. **It is not PKI and carries no
  eIDAS-equivalent legal weight** — this is stated in the docblock and must
  not be overstated to anyone.
- Applications are addressed in URLs by `application_number`, not `id`, so
  sequential ids are not published. Keep it that way.
- `AppServiceProvider` registers a `Gate::before` granting
  `super_admin`/`admin` everything — which is exactly why **admin screens
  must ALSO check permissions explicitly and audit**, rather than relying on
  a policy returning true.
- `Model::shouldBeStrict()` is on outside production: a nonexistent attribute
  or a lazy-loaded un-eager-loaded relation throws for real in local.
- Never forward a raw `$e->getMessage()` to a user. Known business exceptions
  carry a Persian message; unexpected exceptions are logged and return a safe
  generic Persian message. (`ProviderException` has separate technical and
  `persianMessage` fields for exactly this.)

---

## 7. Rate limiting

**Laravel's bare `throttle:N,M` keys an authenticated request by user id
only — not by route.** That means one shared bucket across every sensitive
action: verification, bank and guarantee calls would drain the signing
bucket, and a customer would hit a 429 mid-signature. This was a real bug.

**Do not use bare `throttle:N,M` on sensitive authenticated rental routes.**
Use the named limiters defined in
`AppServiceProvider::configureRateLimiters()`, whose keys include the limiter
name:

| Limiter | Budget |
|---|---|
| `verification-identity` | 6 / 10 min |
| `verification-media` | 10 / 10 min |
| `verification-bank` | 6 / 10 min |
| `rental-guarantee` | 6 / 10 min |
| `rental-signature-otp` | 5 / 10 min — a bucket separate from login OTP |
| `rental-signature` | 5 / 10 min |

Adding a sensitive authenticated route means adding a named limiter, not
reaching for `throttle:N,M`.

---

## 8. Environment safety

### The database

**Rental MUST use its own database: `gamepek_rental`.**
**NEVER connect Rental to the Store's `gamepek` database — not to read, not
to write.**

`.env.example` ships `DB_DATABASE=gamepek_rental`, `APP_NAME="GamePek Rental"`
and `CACHE_PREFIX=gamepek_rental_`. Laravel derives the session cookie name and
cache prefix from `APP_NAME`, so keeping the Rental name distinct is what stops
the two apps colliding on a shared host or cache store. Do not change these back.

Still verify the live target before any command that touches the database:

```bash
php artisan tinker --execute="echo DB::selectOne('SELECT DATABASE() AS d')->d;"
# must print: gamepek_rental
```

### Verification env vars — never in production

> ⚠️ `VERIFICATION_IDENTITY_REQUIRED_CHECKS` and
> `VERIFICATION_GUARANTEE_REQUIRED_INQUIRIES` exist **only** so the automated
> path can be exercised end to end in local testing. They are **not
> owner-approved policy (B1 / B6)**. Setting either in production
> **auto-verifies real people's identities and real cheque guarantees on a
> guess**. They must NEVER appear in a production `.env`, and must be removed
> before any production deploy.

The shipped default for both is empty, which is fail-closed: nothing
auto-verifies and everything waits for an admin. Keep the default empty.

### Other environment notes

- `PAYMENT_GATEWAY=mock`. No live gateway is connected.
- `MELIPAYAMAK_API_KEY` is empty; `NullOtpProvider` delivers OTP locally so
  login works in development (code appears in `storage/logs/laravel.log` and
  as `dev_otp` in the JSON response). No real SMS is sent.
- `config/filesystems.php` reads `STORAGE_PUBLIC_PATH`. On the production
  host the document root sits *above* the Laravel root, so uploads must be
  written to the served `storage/` directory or Apache will never see them.
  `ImageUploadService::storeAndVerify()` re-checks the file exists on disk
  after writing, because on that host a write can report success without
  being immediately visible.
- `config/rental.php` is the single app config (OTP, payment, cart, pricing,
  reservation, lifecycle, contract, SMS, pagination, admin roles, search,
  catalog facets). `config/verification.php` holds every external-integration
  seam. `config/services.php` holds MeliPayamak and gateway credentials.

---

## 9. Known issues — documented, NOT fixed

These are real and currently present. Do not "discover" them again, and do
not fix one as a side effect of unrelated work — each needs its own decision
or its own change.

**Data integrity**

1. **Two competing sources of availability truth.** The search filter queries
   `rental_reservations` (real). The **product-page calendar**
   (`partials/rental-details.blade.php` via `RentalItem::blocked()`) reads
   `attributes._rental.blocked` — a **static JSON array seeded by the demo
   seeder**. A device genuinely reserved through the app still shows as free
   on its own product page.
2. **Reservations are never released.** `ReservationState::Held` is the only
   state ever written after `Draft`; `Paid`, `Active`, `Cancelled`, `Expired`,
   `Returned` and `Closed` are written nowhere. `blocking()` includes `held`.
   So a **cancelled or rejected application keeps blocking its dates
   forever** (`cancel()` and `reject()` do not touch the reservation), and an
   abandoned draft blocks permanently because `hold_minutes` is null (B10).
3. **No step gating before reserve/pay.** `reserve()` and `pay()` check only
   `authorize('update')` (ownership + non-terminal). Neither requires a
   verified identity or bank account. A customer can POST directly to
   `/reserve` then `/pay` with identity still `Draft`; `nextState()` then
   correctly derives `Paid`, skipping `IdentityVerified` and `BankVerified`,
   because the ladder returns the highest rung that **holds**, not a check
   that lower rungs were passed. The UI only offers the next incomplete step,
   but the routes are directly reachable. **Whether ordering must be enforced
   is an owner decision** (interacts with B1 / B8).

**Broken**

4. **`/admin/users/{user}` throws.** `Admin\UserController::show()` calls
   `loadCount(['orders', 'wishlists', 'reviews', 'questions'])`, but `User`
   has no `wishlists`, `reviews` or `questions` relations — those Store
   features were pruned. `admin/users/show.blade.php` also reads
   `wishlists_count`, `reviews_count`, `questions_count`. No test covers it.

**Configuration and leftovers**

5. ~~`.env.example` hazards~~ — **RESOLVED (foundation phase).**
   `.env.example` now targets `gamepek_rental`, carries a distinct `APP_NAME`
   and `CACHE_PREFIX`, and documents all 70+ variables the code actually reads.
   `VITE_APP_NAME` (no Vite here) was dropped; `ZARINPAL_*` / `IDPAY_*` remain
   but are labelled as unregistered — they resolve to `UnconfiguredGateway`.
6. ~~Store setup scripts / Docker identity~~ — **RESOLVED (foundation phase).**
   The eight Store scripts that create/migrate `gamepek` or `cd` into the Store
   directory are quarantined in `scripts/legacy-store/` with a README; only
   path-relative, Rental-safe scripts remain in the root. Docker containers,
   network, volume and provisioned database are renamed `gamepek_rental_*`.
   `composer.json` is now `gamepek/gamepek-rental`.
6b. **Remaining Store leftovers (not yet addressed).** `app/helpers.php` carries
   a dead `digital_code` status-label block; banner admin forms suggest
   `/gta-vi` (a Store route that does not exist here); `add-hosts.sh` still uses
   the Store's `gamepek.test` hostname; and `Admin\UserController` still
   references three pruned Store relations (see issue 4 above).

**Not implemented**

7. **No live payment provider.** Pardakht Novin's adapter and config exist,
   carried from the Store, but no rental merchant credentials are set.
8. **No live KYC / bank-ownership / guarantee provider.** All four seams
   (identity, bank_ownership, guarantee, sms) have interface + `Fake` +
   `Unconfigured` only. No vendor has been chosen. `base_url`, `error_map`,
   `legal.permission_reference`, `source.authority` and
   `cost.per_inquiry_rial` are deliberately null — do not invent them.
9. **No owner / lessor domain.** There is no owner entity, no device
   registration, no owner verification, no owner approval and no owner
   settlement anywhere in the codebase.
10. **No post-approval lifecycle.** Active / Returned / Closed are blocked by
    B14 as described in §3.
11. **No delivery / pickup / inspection / return / damage workflow.** Only a
    flat `delivery_fee` in pricing, and unused media kinds
    (`handover_video`, `return_video`) with no workflow attached.
12. **Wallet has a real backend now, but nothing writes to it yet.**
    `App\Services\Wallet\WalletService` is the single writer of
    `wallets.balance`, backed by a persisted, immutable ledger
    (`wallet_transactions` — see §16 below). `Admin\WalletController` reads
    real data (`admin/wallet`); it has no credit/debit form, on purpose.
    **The customer profile's wallet tab (top-up, withdraw) is still
    `localStorage` in Blade and is untouched by this backend — the two are
    not connected.** Nothing in the codebase calls `WalletService::credit()`
    or `debit()` yet: no settlement, owner payout, deposit, refund or
    damage-charge logic exists or is invented by this service. **Do not
    treat the profile wallet tab's figures as real money**, and do not wire
    it to the real backend without a decision on what triggers a real
    movement.
13. **Rental SMS is not implemented.** `config('rental.sms.templates')` and
    `state_templates` are both empty (B13). No SMS fires on any state change;
    `SmsService` records `sms.template_undefined` so the gap stays visible.
    OTP delivery is a **separate** channel and is not affected.

**Inherited, worth scheduling** — Tailwind's play CDN is not production-
intended and Google Fonts is a latency/availability risk for Iranian hosting;
no loading/skeleton states (filtering and sorting are full page loads); Terms
content is hardcoded in Blade rather than DB-driven; dates outside the rental
screens still render Gregorian.

---

## 10. Domain unknowns — UNKNOWN, requires owner decision

Do not resolve any of these by choosing. Ask.

1. ~~GamePek-owned devices vs third-party owners~~ — **ANSWERED: both.** The
   fleet is mixed. `devices.ownership` is `gamepek` or `owner`, with a CHECK
   constraint binding it to `owner_id`. GamePek stock needs no fake owner
   account. Implemented in the owner/device phase.
2. ~~One `Product` row = one physical device, or a pool?~~ — **ANSWERED: a
   pool.** One product, many `devices`, each with its own unique serial.
   **`Device` IS the rentable unit** — there is deliberately no separate
   `device_units` table, because a device row already carries exactly one
   serial and a 1:1 satellite would be duplication with no invariant behind
   it. See the devices migration.
3. **Product naming / domain model.** The catalog entity is still
   `Product`/`products`. Renaming is a domain decision that has not been
   made. Note the distinction now matters: `products` is the catalog item,
   `devices` is the physical inventory.
3b. **Which free device a paid reservation gets.** The allocation RULE is
   **undecided** (prefer GamePek stock? rotate for owner fairness? favour
   condition?) and it interacts with the 35/65 split and daily settlement.
   Nothing in the code chooses a device. A paid reservation is still made at
   product level (Phase 02), and the operational pickup task that comes with
   it sits in `awaiting_device_allocation` until a human names a device
   explicitly on the admin operations screen.
   `RentalOperationService::attachDevice()` validates the device it is GIVEN;
   there is deliberately no overload that finds one. Do not add one.
   **Since then:** `attachDevice()` also refuses (with an audited
   `operation.device_attach_denied`) a device already committed to a
   *different* blocking reservation with overlapping dates — a safety check
   against double-booking one physical unit, not a selection policy. It
   changes nothing about which device a human should pick, and does not touch
   product-level availability/capacity (`RentalAvailabilityService` still
   treats one product as one concurrently-blockable slot regardless of
   device count — see `docs/operations/OPERATIONS_AND_CUSTODY.md` §10).
3c. **What follows a failed pickup.** Recording a failure does nothing else:
   no refund, no owner penalty, no replacement device, no reservation
   cancellation, no suspension. Every one of those is undecided — see
   `docs/business/CONFIRMED_DECISIONS.md` §4.
3d. **Legal effect of a custody handover.** `custody.acknowledged` is the
   owner confirming GamePek's record of the handover. It is **not** a
   signature, not legal acceptance, and says nothing about the condition of
   the device. Receipt and signature requirements are an open legal gate, and
   physical inspection is a later phase.
4. **Store user-data import status.** The `users`, `addresses` and
   `otp_codes` schemas are deliberately identical to the Store's because the
   owner intends to import existing user data before launch. Whether that has
   happened is UNKNOWN.
5. **Deployment status.** Whether this is deployed, where, and whether any
   environment holds real data is UNKNOWN. Assume it might, and treat every
   database operation as if it does.
6. **Which of B1–B14 have since been finalized.** The register in §5 reflects
   the code as of `554befa`. Decisions may have been made outside the
   repository. Confirm before relying on any entry being still open.
7. Whether the 90-day maximum rental window and the Tehran-only city list are
   real business rules or scaffolding.

---

## 11. Relationship to GamePek Store

- **Rental is a separate repository, a separate application and a separate
  database.** `gamepek-backend` is never modified by work here.
- **Do not modify the Store.** Not its code, not its database, not its
  configuration — for any reason.
- **Do not assume Store files exist here.** This codebase was cloned from
  `gamepek-backend` and **pruned**. Many Store paths, models, relations and
  routes are gone. Check before referencing.
- **It is a fork, not a dependency.** No shared package, so a change here
  does not reach the Store and vice versa; the two will drift. That was an
  explicit trade-off to keep the live Store safe.
- **Reuse only explicitly approved concepts.** What was deliberately carried
  over: the design language, the admin shell, OTP auth, the catalog / cart /
  checkout / order / payment spine, `ImageUploadService`, the activity logs,
  and the `users` / `addresses` / `otp_codes` schemas.
- **Do not blindly copy Store features.** Do not port code from
  `gamepek-backend` to solve a problem here without checking that it fits the
  rental domain and that its dependencies survived the pruning.
- **Keep Store-only features excluded.** Digital codes, blog, reviews,
  product questions, wishlist and the GTA VI / PSN gift-card campaigns are
  Store features and were not cloned. **Do not reintroduce them.** (Note that
  stale references to some of them still exist — see §9.4 and §9.6.)
- **The `users`, `addresses` and `otp_codes` migrations must stay
  schema-compatible with the Store's.** Do not "improve" those three.

---

## 12. Frontend conventions

- `<html lang="fa" dir="rtl">` in `layouts/app.blade.php`. Preserve
  Persian/RTL and responsive behaviour in **every** change.
- In RTL, use the project's existing spacing idiom (`ml-1` for the gap after
  an icon). Arrow direction is reversed: "next" = `fa-chevron-left`,
  "previous" = `fa-chevron-right`. The Jalali calendar follows this.
- **Design tokens live only in
  `resources/views/partials/design-tokens.blade.php`.** Never define a colour
  inline in a page.
- **The catalog card is `resources/views/partials/product-card.blade.php`.**
  There is exactly one; do not fork it. It is already rental-aware — it shows
  the daily rate, carries the selected date range in its link, and has no
  add-to-cart button for rentals.
- **The rental search bar is
  `resources/views/partials/rental-search-bar.blade.php`.** One component,
  three variants (`hero`, `compact`, `catalog`). There is no second copy.
- **Numbers and dates:**
  - Persian digits in Blade → `persian_number($n)`
  - **Every date shown to a customer uses `Jalali::formatLong($iso)`**
    («۳ مهر ۱۴۰۵», Persian digits). `Jalali::format($iso)` is the Latin-digit
    variant.
- **`dir="ltr"` on every mixed LTR field** so digits do not reorder: mobile
  number, card number, IBAN, order number, application number, Sayad id,
  `correlation_id`.
- **Jalali PHP/JS parity is a maintenance rule.** `App\Support\Rental\Jalali`
  and its JS port in `partials/jalali-datepicker.blade.php` implement the same
  algorithm and the same leap rule. **If one changes, the other must take the
  same change.** The dependency is written in both docblocks.
- There is **one** date range per booking. The product page deliberately has
  **no second calendar** — two calendars means two contradictory sources for
  one range. The range travels via the query string.
- **No raw exception message reaches a user.** Persian for known business
  errors; logged + safe generic Persian for everything else.
- Admin: destructive actions use `data-confirm="…"` (intercepted by
  `adminConfirm()`), **not** native `confirm()`. Feedback uses
  `adminToast()`, not `alert()`.
- Admin list pages are built from the shared components in
  `resources/views/components/admin/` — `admin/users/index.blade.php` is the
  reference implementation. Do not copy-paste table/filter/badge markup.
- Wide tables scroll inside their own `overflow-x-auto`; the page body never
  scrolls horizontally.
- **No build step.** Do not add `package.json`, Vite, or a JS dependency
  without being asked.

---

## 13. Database rules

See `.claude/rules/database.md` for the full set. The non-negotiables:

- **Migrations are forward-only. Never edit a historical migration.** Create
  a new one instead. Two of them are MySQL-only and must not be touched (§2).
- **Never run `migrate:fresh`, `migrate:refresh` or `db:wipe`**, and never
  seed against non-local data. Assume an environment may hold real data
  (§10.5).
- **Respect the existing schema decisions**, in particular:
  - Rental facts live in `products.attributes['_rental']` JSON — there are no
    rental columns, and adding one is a domain decision, not a cleanup.
  - Identity is a 1:1 **satellite table** (`user_identities`), not columns on
    `users` — both for Store schema compatibility and so identity data is
    independently purgeable.
  - `restrictOnDelete` for retention-sensitive parents (orders, products,
    identities, bank accounts); `cascadeOnDelete` only for records that are
    meaningless without their parent.
  - Reservations carry a **price and product snapshot** so a later price
    change never rewrites history.
  - `audit_events` and `rental_application_transitions` are append-only.
- Report every schema change with its `down()` behaviour and data-loss risk.
- Check for unique constraints and race-condition backstops — application
  checks alone are not sufficient. Use `lockForUpdate()` where two requests
  could both pass a check before either writes.
- Never use `forceDelete()` to work around a foreign key or soft delete.
- Never delete or truncate data to make a failing check pass.

---

## 14. Documentation map

| Document | Role |
|---|---|
| **`CLAUDE.md`** (this file) | Operational rules and current project state |
| **[`docs/rental-flow-fa.md`](docs/rental-flow-fa.md)** | **The deep technical reference.** Persian/RTL, 762 lines: chain map, Jalali internals, search data path, payment contract, verification/contract design, admin panel, audit layer, 12 documented bug fixes with their real-world impact, RTL conventions, test matrix, manual test scenario with fixture data, and the B-register. **Read it before non-trivial rental work.** |
| **[`docs/operations/OPERATIONS_AND_CUSTODY.md`](docs/operations/OPERATIONS_AND_CUSTODY.md)** | **The operations and custody domain.** Why custody is not ownership, the pickup state machine, why no device is ever chosen automatically, why GamePek-owned stock gets no transfer row, and the explicit list of what is NOT implemented. Read it before touching `rental_operations` or `device_custody_transfers`. |
| **[`docs/business/CONFIRMED_DECISIONS.md`](docs/business/CONFIRMED_DECISIONS.md)** | **Confirmed business policy.** What is decided (C-01…C-30), where the code still diverges from it, and what remains a policy/legal gate. Check here before assuming a rule is undecided — and before assuming a confirmed rule is implemented. |
| **[`docs/integrations/PROVIDER_STATUS.md`](docs/integrations/PROVIDER_STATUS.md)** | Per-integration status: implemented / fake / unconfigured / TBD, with the env vars and interfaces for each |
| `README.md` | Stack, local setup, seeded accounts, storage notes, provider and payment status |
| `scripts/legacy-store/README.md` | Why the inherited Store scripts are quarantined and must not be run |
| `docs/audits/` | Point-in-time audit reports |
| `.claude/rules/` | Path-scoped rules: `admin-panel.md`, `backend-services.md`, `database.md`, `frontend-blade.md` |

There is **no API documentation**; `routes/api.php` is undocumented.

Note: `docs/rental-flow-fa.md` describes the **architecture as built**, which in
several places differs from confirmed business policy (reservation ordering,
KYC gating, device units). Its header now carries that warning and links to the
confirmed-decisions document. Its test counts were corrected to the verified
`171 tests, 1140 assertions`.

---

## 15. Development workflow — mandatory

### BEFORE changing code

- **Inspect the existing implementation.** This repository is more built than
  it first appears; assume the behaviour may already exist.
- **Check whether the behaviour already exists** before writing it.
- **Understand the dependencies** — which services, which call sites. Shared
  services (`CartService`, `OrderService`, `PaymentService`,
  `InventoryService`, `RentalChainOrchestrator`) are called from **both**
  public and admin paths. Search **all** call sites before changing one.
- **Read the relevant documentation and rules** — this file,
  `docs/rental-flow-fa.md`, and the matching `.claude/rules/` file.
- **Identify whether the change touches money, identity, contracts, minors,
  personal data, guarantees, or legal liability.** If it does, slow down:
  state the risk explicitly, confirm the policy exists (§5), and do not
  proceed on an assumption.

### DURING implementation

- **Make the smallest safe change.**
- **Do not perform unrelated refactors.** Not renames, not reformatting, not
  "while I'm here" cleanups.
- **Do not invent business rules** (§5).
- **Preserve the existing architecture** — the derived-state principle (§3)
  above all.
- **Keep the fail-closed behaviour intact** (§4). Never weaken a guard to
  make something work.
- Preserve Persian/RTL and responsive behaviour (§12).
- Do not add dependencies, hooks or global settings without being asked.

### AFTER implementation

- **Run the relevant tests** (`vendor/bin/phpunit`, needs the
  `gamepek_rental_test` MySQL database). Run `vendor/bin/pint` on what you
  touched.
- **Independently verify the change** — exercise the affected page *and* the
  admin screen that touches the same service. A passing test is not proof the
  screen renders.
- **Check for regressions**, especially on shared services with both public
  and admin call paths.
- **Report exactly what changed**, faithfully. If a test failed, say so with
  the output. If you skipped something, say so and why.
- **Do not modify unrelated files.**

---

## 16. Output Compression contract

How work in this repository is reported back. Applies to every response.

**Inspect before editing.** Read the existing implementation first. This
codebase is more built than it looks; assume the behaviour may already exist.
Never edit a file you have not read in the current session.

**Do not dump files.** Never paste a whole file, a whole diff, or long command
output into a response. Quote the smallest fragment that carries the point —
usually one to five lines — and reference the rest as `path:line`.

**Do not repeat unchanged information.** Do not restate the architecture, the
rules in this file, or anything already established earlier in the
conversation. Say what changed, not what stayed the same.

**Report changed files as a list.** One path per line, one short reason each.
No before/after blocks unless the reason is genuinely unclear without one.

**Report tests as command + result.** For example
`vendor/bin/phpunit → OK (171 tests, 1140 assertions)`. On failure, name the
failing test and the assertion — not the full trace.

**Report blockers briefly.** What is blocked, and the one fact that blocks it.
No options survey unless asked.

**Report the commit hash** when a commit is made.

**Keep responses compact.** Prose in short paragraphs; tables only when
comparing. Length should track what actually changed, not effort spent.

**Never claim completion without verification.** "Done", "fixed" and "working"
require an executed check — a passing test, a real HTTP response, a query
result. A file that was written is not a feature that works. If something was
not verified, say so explicitly. If a test failed, say so with the output. If
part of the task was skipped, say which part and why.
