# GamePek Rental

Rental platform for game consoles and accessories — the second GamePek business
area. The GamePek Store (`gamepek-backend`) is a **separate application with a
separate database** and is never modified by work here.

Persian, RTL throughout.

## What this is today

Not a foundation — a working customer-side rental application chain, plus the
admin screens to review it.

A customer can search by city and Jalali date range, see genuinely available
devices, reserve, pay (mock gateway), submit identity and bank details, submit a
cheque guarantee, receive a generated contract, accept it, sign it with a
dedicated OTP, and wait for an admin. An admin can review and approve or reject.
Every step writes an audit row. This is covered by 171 passing tests.

What does **not** exist yet: the owner/lessor domain, the physical device and
device-unit entities, pickup, inspection, delivery, return, damage, settlement,
the wallet backend, and every lifecycle notification. No external integration is
live — payment, identity, bank-ownership, cheque and SMS are all fake or
unconfigured.

See [`CLAUDE.md`](CLAUDE.md) for the operational rules and current known issues,
and [`docs/rental-flow-fa.md`](docs/rental-flow-fa.md) for the deep technical
reference.

## Tech stack

| | |
|---|---|
| Framework | Laravel **11** (`v11.54.0` in `composer.lock`) |
| PHP | **^8.2** (verified on 8.2.12) |
| Database | **MySQL / MariaDB only.** Not SQLite — two historical migrations use `fullText()` and a raw `ALTER TABLE … ADD CONSTRAINT` |
| Production dependencies | Three: `laravel/framework`, `laravel/tinker`, `spatie/laravel-permission` |
| Frontend | Blade only. **No build step** — no `package.json`, no Vite, no `resources/js` |
| CSS | Tailwind via the **play CDN** (`cdn.tailwindcss.com`), compiled in the browser |
| Fonts | Vazirmatn via Google Fonts; Font Awesome via cdnjs |
| JavaScript | Inline in Blade |
| Auth | Laravel `web` guard; roles/permissions via `spatie/laravel-permission` |
| Tooling | `laravel/pint` (the only formatter), PHPUnit 11 |

Design tokens live in exactly one place:
`resources/views/partials/design-tokens.blade.php`.

> The CDN approach is inherited from the Store and is a known production concern
> for Iranian hosting — the Tailwind play CDN is not intended for production and
> Google Fonts is a latency/availability risk. Not addressed yet.

## Database

**Rental uses `gamepek_rental`. The Store uses `gamepek`. They must never be
crossed** — not to read, not to write.

```sql
CREATE DATABASE gamepek_rental      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE gamepek_rental_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The test database is deliberately separate so `RefreshDatabase` can never
truncate development data.

Verify the live target before any command that touches the database:

```bash
php artisan tinker --execute="echo DB::selectOne('SELECT DATABASE() AS d')->d;"
# must print: gamepek_rental
```

## Setup

Prerequisites: PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`,
`xml`, `ctype`, `fileinfo`, `curl`, `zip`, `bcmath`; MySQL 8 or MariaDB 10.4+;
Composer.

```bash
composer install
cp .env.example .env          # already targets gamepek_rental
                              # set DB_USERNAME / DB_PASSWORD for your machine
php artisan key:generate      # APP_KEY also keys the OTP HMAC and the contract signature
php artisan migrate           # forward-only — never migrate:fresh / refresh / db:wipe
php artisan db:seed           # users, settings, shipping, home sections, menu,
                              # rental navigation, contract template
php artisan serve
```

Optional local fixtures — four demo rental consoles with invented prices. Not in
`DatabaseSeeder`; refuses to run in production:

```bash
php artisan db:seed --class=RentalDemoProductSeeder
```

> **There is no bundled Rental setup script.** The `setup.sh`, `install.sh`,
> `install.bat`, `setup-xampp.bat`, `migrate.sh`, `migrate.bat`, `deploy.bat` and
> `clear-cache.bat` scripts are inherited **Store** scripts — they create and
> migrate the `gamepek` database and several hardcode the Store's project path.
> They are quarantined in [`scripts/legacy-store/`](scripts/legacy-store/README.md)
> and must not be used for Rental.
>
> `deploy.sh` and `clear-cache.sh` remain in the root and are path-relative and
> Rental-safe.

## Running locally

```bash
php artisan serve             # http://127.0.0.1:8000
```

Other useful commands:

```bash
vendor/bin/pint               # format (the only linter configured)
php artisan payments:reconcile        # PAY-06 reconciliation sweep
php artisan verification:purge-media  # skips any kind whose retention is null
```

## Testing

```bash
vendor/bin/phpunit            # requires the gamepek_rental_test database
```

Current baseline: **OK (171 tests, 1140 assertions)** — 170 feature, 1 unit,
0 browser tests.

## Authentication (development)

Customers sign in with mobile + OTP at `/auth/login`. Admins sign in with email
+ password at `/admin/login`. Both use the same `users` table and `web` guard;
admin access is role-based via `config('rental.admin.roles')`.

Seeded local accounts — **development only, not production credentials**:

| Account | Credentials |
|---|---|
| Dev super-admin | `admin@gamepek-rental.local` / `password` → `/admin/login` |
| Production admin | mobile `09100000001`, role `super_admin`, no password |
| Test customer | mobile `09120000002` |

With `MELIPAYAMAK_API_KEY` empty, `NullOtpProvider` handles delivery: the login
code is written to `storage/logs/laravel.log` and returned as `dev_otp` in the
JSON response. **No real SMS is sent.**

Catalog taxonomy, banners and quick-category tiles are **not** seeded — they are
content, and the rental taxonomy has not been designed. Create them in the admin
panel.

## Providers and integrations

Every external seam follows the same shape: an interface, a DTO, a `Fake`
adapter and an `Unconfigured` adapter.

**The binding rule matters more than the bindings.** A driver with no registered
adapter resolves to the `Unconfigured` provider, which **throws**. It never
silently falls back to a fake. The `fake` driver is additionally refused outside
`local` and `testing`, so a fake can never answer a real customer.

| Seam | Status | Notes |
|---|---|---|
| Identity (Shahkar, civil registry, liveness, face match) | **FAKE** | No vendor selected |
| Bank account ownership | **FAKE** | No vendor selected |
| Guarantee / Sayad cheque | **FAKE** | No vendor selected |
| Rental lifecycle SMS | **UNCONFIGURED** | No provider, no approved copy — nothing is ever sent |
| Login OTP | **UNCONFIGURED** | MeliPayamak key empty; `NullOtpProvider` in local |
| Contract signature | **INTERNAL HMAC** | Not PKI — see below |
| Payment gateway | **MOCK** | See below |

**Fake providers are development and testing only.** No verification result
produced today reflects reality.

`base_url`, `error_map`, `legal.permission_reference`, `source.authority` and
`cost.per_inquiry_rial` are deliberately null for every provider — no endpoint,
response code, legal basis or tariff has been invented.

Full per-integration status:
[`docs/integrations/PROVIDER_STATUS.md`](docs/integrations/PROVIDER_STATUS.md).

## Payment

**Mock only. No live gateway is connected.** `PAYMENT_GATEWAY=mock`.

`GatewayRegistry` makes the mock **structurally unresolvable** outside
`local|testing`, so a `PAYMENT_GATEWAY` typo cannot run it in production — it
resolves to `UnconfiguredGateway`, which fails closed. `MockPaymentController`
additionally 404s outside those environments and checks transaction ownership.

The Pardakht Novin adapter is implemented and registered but has **no rental
merchant credentials**. Zarinpal and IDPay appear in config but are **not**
registered as gateways; they resolve to `UnconfiguredGateway`.

The settlement path is real: `verify()` is the only source of "paid",
`GatewayCallback` has no `success` field by design, and `PaymentService::settle()`
locks the row, is idempotent, and fails on amount mismatch.

**The deposit is never charged.** Order total is `payable_now`, which excludes
it. How a deposit is held is an undecided business decision.

## Security

- **Sensitive data** — national code, PAN, IBAN and Sayad id are stored as
  `*_encrypted` + `*_hash` (keyed HMAC for lookup and uniqueness *without*
  decryption) + `*_mask` (the only form rendered). Raw values never reach Blade.
- **OTP codes** are stored as a keyed hash only. The contract signing OTP lives
  in its own table with its own expiry, attempt cap and rate limiter — a login
  OTP can never sign a contract.
- **Verification media** lives on a private disk, never the public one. It is
  served only via `URL::temporarySignedRoute` + an explicit ownership check +
  `Cache-Control: private, no-store`, and **every read writes an audit row**.
- **Audit logging** — `audit_events` is append-only (no `updated_at`).
  `AuditLogger` redacts 13 sensitive keys at any depth. `AssignCorrelationId`
  mints a UUID per request so all rows from one request are traceable.
- **Rate limiting** — named limiters in `AppServiceProvider`. Laravel's bare
  `throttle:N,M` keys authenticated requests by user id only (one shared bucket
  across all actions), so it must not be used on sensitive authenticated rental
  routes.
- **Fail-closed everywhere** — unconfigured providers throw, unknown gateways
  throw, undefined policy refuses and records `*.policy_undefined`.
- **Contract rendering** uses `strtr` over `e()`-escaped values, never
  `Blade::render()` — an admin-editable template must not become an RCE path.
- The internal signature is `hash_hmac('sha256', contentHash|userId|signedAt,
  APP_KEY)`. It proves integrity and phone possession. **It is not PKI and its
  legal standing has not been established.**

## Architecture

```
Controllers → Services → Eloquent
```

No repository layer. Pure logic with no database access lives in `app/Support/`
(`Jalali`, `RentalItem`, `RentalQuote`, `Availability`, `CardNumber`, `Iban`,
`SayadId`).

The central rule: `RentalApplication.state` is **derived, never commanded**.
`RentalChainOrchestrator` is the **only** writer of that column. Step services
mutate their own child record and then call `advance()`, which locks the row,
re-derives the state, and writes nothing if nothing changed. `state` is not
fillable. Only `approve()`, `reject()` and `cancel()` are non-derived.

Conventions:

- Admin list pages are built from `resources/views/components/admin/` —
  `admin/users/index.blade.php` is the reference implementation
- Destructive admin actions use `data-confirm`, not native `confirm()`; feedback
  uses `adminToast()`, not `alert()`
- There is exactly one catalog card (`partials/product-card.blade.php`) and one
  rental search bar (`partials/rental-search-bar.blade.php`) — do not fork them
- Every customer-facing date renders through `Jalali::formatLong()`
- Migrations are forward-only; never edit a historical migration

## Relationship to the Store

This codebase was cloned from `gamepek-backend` and pruned. It is a **fork, not
a dependency** — no shared package, so the two will drift. That was an explicit
trade-off to keep the live Store safe.

The `users`, `addresses` and `otp_codes` schemas are deliberately identical to
the Store's because existing user data is intended to be imported before launch.
Do not "improve" those three migrations.

Digital codes, blog, reviews, product questions, wishlist and the GTA VI / PSN
gift-card campaigns are Store features and were not cloned. Do not reintroduce
them.

## Known gaps

Tracked in [`CLAUDE.md §9`](CLAUDE.md) and in detail in
[`docs/audits/GAMEPEK_RENTAL_COMPLETE_AUDIT.md`](docs/audits/GAMEPEK_RENTAL_COMPLETE_AUDIT.md).

Headline items: two competing sources of availability truth; reservations that
are never released; no verification gating before payment; `/admin/users/{id}`
returns 500; no owner/device domain; no operations workflows; wallet is a
frontend prototype; no notifications.

**Nothing in this project is production-ready.** No real payment, no real
identity verification, and several unresolved legal questions.
