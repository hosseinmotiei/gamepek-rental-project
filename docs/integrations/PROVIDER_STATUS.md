# GamePek Rental — external integration status

Per-integration status, verified against the repository. Last verified
2026-09-08.

## Status vocabulary

| Status | Meaning |
|---|---|
| **IMPLEMENTED** | Real vendor wired, configured, and reachable in at least one environment |
| **PARTIALLY IMPLEMENTED** | Some of the chain exists; the rest is missing |
| **FAKE / DEVELOPMENT** | Interface + deterministic local adapter. Makes no network call. Refused outside `local`/`testing` |
| **UNCONFIGURED** | Seam exists; no vendor selected or no credentials. Fails closed (throws) |
| **PROPOSED** | Discussed, no code |
| **TBD** | Requires a business or legal decision before it can be specified |

> **No integration in this repository is currently IMPLEMENTED.**
> Every external call today is either fake, unconfigured, or mock.

## The binding rule

`App\Providers\IntegrationServiceProvider`:

- A driver with **no registered adapter class** resolves to the `Unconfigured*`
  provider, which **throws**. It never silently falls back to a fake — a
  verification that appears to succeed because nothing was wired is the exact
  failure mode this layer exists to prevent.
- The `fake` driver is **refused outside `local` and `testing`**.

Every provider block in `config/verification.php` shares one shape. `base_url`,
`error_map`, `legal.permission_reference`, `source.authority` and
`cost.per_inquiry_rial` are deliberately `null` for all of them: no endpoint,
response code, legal basis or tariff has been invented. Those are facts the
owner supplies.

---

## Identity / KYC

| Sub-integration | Status | Evidence |
|---|---|---|
| National ID storage + validation | **IMPLEMENTED** (internal) | encrypted + HMAC hash + mask; no external call |
| **Shahkar** (mobile ↔ national ID) | **FAKE / DEVELOPMENT** | `IDENTITY_DRIVER=fake` → `FakeIdentityProvider` |
| **Civil Registry** | **FAKE / DEVELOPMENT** | same driver |
| **Liveness** | **FAKE / DEVELOPMENT** | check type exists; no capture UI found |
| **Face Match** | **FAKE / DEVELOPMENT** | check type exists; no capture UI found |

- Interface: `App\Services\Identity\Contracts\IdentityProviderInterface`
- Config: `config/verification.php` → `identity`
- Env: `IDENTITY_DRIVER`, `IDENTITY_ENV`, `IDENTITY_BASE_URL`,
  `IDENTITY_API_KEY`, `IDENTITY_API_SECRET`, `IDENTITY_WEBHOOK_SECRET`
- Rate limiter: `verification-identity` (6 / 10 min)

**Fail-closed behaviour is real and verified:** `required_checks` ships empty, so
even a *passing* check does not promote an identity. It lands in
`manual_review`, `kyc_level` stays 1, and `identity.policy_undefined` is audited
as `denied`.

**TBD (business):** which checks are mandatory for KYC level 2; liveness and
face-match score thresholds; attempt cap; rejection and appeal behaviour.

**TBD (legal):** minors and guardian consent; biometric consent; retention of
identity documents and video.

---

## Bank account ownership

| | |
|---|---|
| Status | **FAKE / DEVELOPMENT** |
| Interface | `App\Services\Banking\Contracts\BankOwnershipProviderInterface` |
| Config | `config/verification.php` → `bank_ownership` |
| Env | `BANK_OWNERSHIP_DRIVER`, `BANK_OWNERSHIP_ENV`, `BANK_OWNERSHIP_BASE_URL`, `BANK_OWNERSHIP_API_KEY`, `BANK_OWNERSHIP_API_SECRET` |
| Rate limiter | `verification-bank` (6 / 10 min) |

Implemented locally without any external call: card Luhn validation
(`Support\Banking\CardNumber`), IBAN mod-97 validation (`Support\Banking\Iban`),
encryption + HMAC hash + mask, and `unique(user_id, value_hash)`.

**What is NOT proven today:** that a bank account belongs to the authenticated
user. The mechanism to record that proof is complete and correct, but the answer
currently comes from `FakeBankOwnershipProvider`, which is deterministic local
fiction.

**PROPOSED / TBD:** card → IBAN resolution and BIN → bank-name lookup exist as
schema fields (`linked_iban_mask`, `bank_name`) but have no verified provider.

---

## Guarantee / Sayad cheque

| | |
|---|---|
| Status | **FAKE / DEVELOPMENT** |
| Interface | `App\Services\Guarantee\Contracts\ChequeProviderInterface` |
| Config | `config/verification.php` → `guarantee` |
| Env | `GUARANTEE_DRIVER`, `GUARANTEE_ENV`, `GUARANTEE_BASE_URL`, `GUARANTEE_API_KEY`, `GUARANTEE_API_SECRET`, `GUARANTEE_ENFORCE_SAYAD_CHECKSUM` |
| Rate limiter | `rental-guarantee` (6 / 10 min) |

| Capability | Status |
|---|---|
| Sayad ID structural validation (16 digits, not all identical) | **IMPLEMENTED** |
| Sayad check-digit rule | **PARTIALLY IMPLEMENTED — disabled by default.** The published algorithm is implemented but `GUARANTEE_ENFORCE_SAYAD_CHECKSUM=false`: no bank specification is available here to confirm it, and rejecting a genuine cheque on an unverified checksum is worse than letting the provider reject it |
| Sayad validate inquiry | **FAKE / DEVELOPMENT** |
| Ownership match inquiry | **FAKE / DEVELOPMENT** |
| Cheque status / bounced-cheque inquiry | **UNCONFIGURED** — inquiry kind exists, no provider |
| Credit / risk inquiry | **UNCONFIGURED** |
| Guarantee blocking, release, enforcement | **MISSING** — no mechanism exists |

A guarantee **never auto-verifies**: `required_inquiries` ships empty, so it
always waits for an admin.

**TBD (business):** guarantee amount formula; which cheque inquiries are
mandatory; credit-risk thresholds; when a guarantee is blocked, released or
enforced.

**TBD (legal):** lawful collection, storage and enforcement of cheques and
promissory notes.

---

## Payment gateway

| Gateway | Status | Notes |
|---|---|---|
| **Mock** | **FAKE / DEVELOPMENT** | Active. Structurally unresolvable outside `local`/`testing` |
| **Pardakht Novin** | **UNCONFIGURED** | Adapter implemented and registered; no rental merchant credentials issued |
| Zarinpal | **UNCONFIGURED** | Config keys exist but the gateway is **not registered** in `config('rental.payment.gateways')` — resolves to `UnconfiguredGateway` |
| IDPay | **UNCONFIGURED** | Same as Zarinpal |

- Interface: `App\Services\Payment\Contracts\PaymentGatewayInterface`
- Env: `PAYMENT_GATEWAY`, `PAYMENT_CALLBACK_URL`, `PARDKHTNOVIN_*`,
  `PAYMENT_RECONCILE_STALE_MINUTES`, `PAYMENT_RECONCILE_LOOKBACK_HOURS`

| Operation | Code | Status |
|---|---|---|
| `request()` — create transaction + redirect URL | PAY-01 | **IMPLEMENTED** (mock) |
| `parseCallback()` — parse only, decides nothing | PAY-02 | **IMPLEMENTED** |
| `verify()` — the only source of "paid" | PAY-03 | **IMPLEMENTED** (mock) |
| `status()` — status enquiry | PAY-04 | **IMPLEMENTED** (mock) |
| `refund()` | PAY-05 | **PARTIALLY IMPLEMENTED** — interface method exists, never called, no refund policy |
| Reconciliation (`payments:reconcile`) | PAY-06 | **IMPLEMENTED** |

`GatewayCallback` deliberately has **no `success` field** — the callback is
parsed, never trusted. `PaymentService::settle()` locks the transaction, returns
the stored result if already successful, treats `Unknown` as still-pending for
reconciliation, and fails on amount mismatch.

**TBD (business):** refund and cancellation rules; deposit hold policy.

---

## SMS

Two **separate** channels. They are deliberately not merged: the OTP contract is
different (the provider generates the code and the body is never seen) and
authentication depends on it.

### Login OTP

| | |
|---|---|
| Status | **UNCONFIGURED** |
| Provider | MeliPayamak |
| Interface | `App\Services\Otp\OtpProviderInterface` |
| Config | `config/services.php` |
| Env | `MELIPAYAMAK_API_KEY` (empty), `MELIPAYAMAK_ENDPOINT`, `MELIPAYAMAK_TIMEOUT` |

With an empty key, `NullOtpProvider` writes the code to
`storage/logs/laravel.log` and returns it as `dev_otp` so login works locally.
**No real SMS is sent.**

### Rental lifecycle SMS

| | |
|---|---|
| Status | **UNCONFIGURED — nothing is ever sent** |
| Interface | `App\Services\Notification\Contracts\SmsSenderInterface` |
| Config | `config/verification.php` → `sms`; templates in `config('rental.sms.*')` |
| Env | `SMS_DRIVER`, `SMS_ENV`, `SMS_BASE_URL`, `SMS_API_KEY`, `SMS_API_SECRET`, `RENTAL_SMS_MAX_ATTEMPTS` |

The infrastructure exists — `SmsService`, the `sms_messages` ledger with
attempts/retry, a delivery-sync command, and audit. **Only the copy is
missing.** `config('rental.sms.templates')` and `state_templates` are both
empty, so no SMS fires on any state change; `SmsService` records
`sms.template_undefined` instead, keeping the gap visible.

Driver defaults to `log` outside production and `unconfigured` in production,
which fails loudly rather than pretending to send.

**TBD (business):** approved Persian copy for every lifecycle event; provider
selection.

**TBD (legal):** transactional vs marketing consent; opt-out.

---

## Digital contract signature

| | |
|---|---|
| Status | **PARTIALLY IMPLEMENTED — internal only** |
| Driver | `internal` (`InternalHmacSignatureProvider`) |
| Interface | `App\Services\Contract\Contracts\SignatureProviderInterface` |
| Env | `SIGNATURE_DRIVER`, `SIGNATURE_ENV`, `SIGNATURE_BASE_URL` |
| Rate limiters | `rental-signature-otp` (5 / 10 min), `rental-signature` (5 / 10 min) |

What works: versioned templates with immutable published rows; rendered text
snapshotted with a sha256 `content_hash`; `strtr` over `e()`-escaped values
(never `Blade::render()`); explicit acceptance separate from viewing; a
dedicated one-time signing OTP bound to that contract and signer; integrity and
signature re-verified before an admin can approve.

The signature is `hash_hmac('sha256', contentHash|userId|signedAt, APP_KEY)`. It
proves the stored contract has not been altered since signing and that the
signer held the phone.

**It is not PKI.** The interface exists so a CA-backed provider can replace it
without touching `ContractService`. No such provider has been selected.

**TBD (legal):** legal standing of this electronic signature; final contract
text (the current template is a placeholder with no approved legal content).

---

## Secure video / media storage

| | |
|---|---|
| Status | **IMPLEMENTED (local disk)** — no external service |
| Config | `config/verification.php` → `media`; `config/filesystems.php` |
| Env | `VERIFICATION_STORAGE_PATH`, `VERIFICATION_SIGNED_URL_MINUTES`, `STORAGE_PUBLIC_PATH` |

Media kinds: `national_card`, `selfie`, `liveness_video`, `handover_video`,
`return_video`, each with its own size cap and MIME allow-list.

Stored on a **private** disk, never the public one (which is a real
Apache-served directory). Served only via `URL::temporarySignedRoute` + an
explicit ownership check + `Cache-Control: private, no-store`, and **every read
writes an audit row**. A purge keeps the row (`state = purged`, `path` nulled)
so the trail of the file having existed survives.

**TBD (business/legal):** retention period per media kind. All are currently
`null`, and `verification:purge-media` **skips** any kind with a null retention —
nothing is ever deleted on a guessed policy.

`handover_video` and `return_video` kinds exist but have **no workflow** — the
pickup/inspection/return domains are not built.

---

## Audit and consent

| | |
|---|---|
| Audit logging | **IMPLEMENTED** |
| Consent capture | **MISSING** — table exists, nothing writes to it |

`audit_events` is append-only (no `updated_at`). Each row carries actor,
action, resource, result (`success` / `failure` / `denied`), correlation id,
request id, IP, user agent and a JSON context. `AuditLogger` redacts
`national_code`, `pan`, `card_number`, `iban`, `sheba`, `sayad_id`, `otp`,
`code`, `password`, `secret`, `token`, `api_key` **at any depth**. Audit write
failures are logged, never surfaced to the user.

`AssignCorrelationId` middleware mints a UUID per request so every row from one
request is traceable together.

The `consents` table exists with `kind` / `version` / `granted_at` /
`revoked_at` / IP / user agent — but **no code writes to it**. Personal data,
bank details and cheque information are currently collected without a recorded
consent record.

**TBD (legal):** what consent must be captured, in what wording, at what
version, before which collection step.

---

## Summary

| Family | Status |
|---|---|
| Identity / KYC | FAKE |
| Bank ownership | FAKE |
| Guarantee / Sayad | FAKE |
| Payment | MOCK (Pardakht Novin unconfigured) |
| Login OTP SMS | UNCONFIGURED |
| Rental lifecycle SMS | UNCONFIGURED (no copy) |
| Contract signature | INTERNAL HMAC, not PKI |
| Secure media storage | IMPLEMENTED (local, private disk) |
| Audit | IMPLEMENTED |
| Consent | MISSING |

**No external vendor has been selected for any integration.** Procurement lead
time is itself a project risk.
