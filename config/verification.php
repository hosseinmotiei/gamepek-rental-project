<?php

/*
|--------------------------------------------------------------------------
| Verification & external-integration contract
|--------------------------------------------------------------------------
|
| Every external service the rental chain talks to is configured through the
| SAME block shape, read by App\Support\Integration\ProviderConfig. Wiring a
| real vendor later is one adapter class, one binding, and filling in a block.
|
| READ THIS BEFORE FILLING ANYTHING IN:
|
|  - `base_url`, `error_map`, `legal.permission_reference`, `source.authority`
|    and `cost.per_inquiry_rial` are deliberately null/empty. No provider has
|    been chosen for identity, bank-ownership, cheque or general SMS, and this
|    codebase must not invent endpoints, response codes, legal bases or
|    tariffs. They are facts the owner supplies.
|  - `driver` defaults to `fake` so the chain is runnable end to end locally.
|    Any driver with no registered adapter class resolves to the
|    `unconfigured` provider, which THROWS rather than silently passing a
|    check. A verification must never appear to succeed because nothing was
|    configured.
|  - Fake providers never make a network call.
|
| The per-provider `fake` block drives the deterministic local adapters:
| `fixtures` maps an input (national code, PAN, IBAN, Sayad id) to an outcome,
| `default_outcome` covers everything else.
|
*/

$defaults = [
    'environment' => 'sandbox',          // sandbox | production
    'base_url' => null,                  // NOT invented -- owner supplies
    'credentials' => ['key' => null, 'secret' => null],
    'timeout' => 10,                     // seconds, per request
    'connect_timeout' => 5,
    'retry' => [
        'times' => 2,
        'sleep_ms' => 500,
        'on' => [429, 500, 502, 503, 504],
    ],
    'rate_limit' => [
        'per_minute' => 30,
        'per_user_per_day' => 5,
    ],
    'sla' => [
        'expected_ms' => 3000,
        'alert_after_ms' => 8000,
    ],
    'callback' => [
        'enabled' => false,
        'url' => null,
        'secret' => null,
    ],
    // Provider status code => internal outcome (pass|fail|unknown|retry).
    // Empty: no provider's code table is known. TODO(integration).
    'error_map' => [],
    'logging' => [
        'log_request' => true,
        'log_response' => true,
        'redact' => ['national_code', 'pan', 'card_number', 'iban', 'sheba', 'otp', 'secret', 'token', 'api_key'],
    ],
    'retention' => [
        'raw_days' => 30,        // raw provider request/response payloads
        'result_days' => 365,    // the pass/fail outcome itself
    ],
    'storage' => [
        'disk' => 'verification',
        'path' => null,
    ],
    'legal' => [
        'permission_reference' => null,   // TODO(business): مجوز حقوقی استعلام
        'consent_text_key' => null,
    ],
    'source' => [
        'authority' => null,              // TODO(business): مرجع رسمی داده
        'description' => null,
    ],
    'cost' => [
        'per_inquiry_rial' => null,       // TODO(business): تعرفه هر استعلام
        'billing' => null,
    ],
    'fake' => [
        'default_outcome' => 'pass',
        'fixtures' => [],
        'latency_ms' => 0,
    ],
];

return [

    /*
    |----------------------------------------------------------------------
    | Identity — Shahkar, civil registry, liveness, face match
    |----------------------------------------------------------------------
    */
    'identity' => array_replace_recursive($defaults, [
        'driver' => env('IDENTITY_DRIVER', 'fake'),
        'environment' => env('IDENTITY_ENV', 'sandbox'),
        'base_url' => env('IDENTITY_BASE_URL'),
        'credentials' => [
            'key' => env('IDENTITY_API_KEY'),
            'secret' => env('IDENTITY_API_SECRET'),
        ],
        'callback' => ['secret' => env('IDENTITY_WEBHOOK_SECRET')],
        'storage' => ['path' => 'identity'],

        // TODO(business) B1: which checks must pass for KYC level 2.
        // Empty means IdentityVerificationService will NEVER auto-promote an
        // identity to Verified -- it logs `identity.policy_undefined` and waits
        // for an admin. Fail closed, always.
        //
        // Shipped empty ON PURPOSE. The env var exists only so the automated
        // path can be exercised end to end in local testing, e.g.
        // VERIFICATION_IDENTITY_REQUIRED_CHECKS=shahkar,civil_registry
        // It is NOT an owner-approved policy: setting it in production would
        // auto-verify real identities on a guess.
        'required_checks' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('VERIFICATION_IDENTITY_REQUIRED_CHECKS', ''))
        ))),

        // TODO(business) B2: passing score for liveness / face match.
        // null routes every scored result to ManualReview.
        'min_score' => null,

        // TODO(business) B3: retry cap after a failed check. null = unlimited,
        // but every attempt is audited.
        'max_attempts' => null,

        // How long a passed check stays valid before it is re-run.
        'cache_ttl_days' => 180,
    ]),

    /*
    |----------------------------------------------------------------------
    | Bank ownership — card / IBAN holder match
    |----------------------------------------------------------------------
    */
    'bank_ownership' => array_replace_recursive($defaults, [
        'driver' => env('BANK_OWNERSHIP_DRIVER', 'fake'),
        'environment' => env('BANK_OWNERSHIP_ENV', 'sandbox'),
        'base_url' => env('BANK_OWNERSHIP_BASE_URL'),
        'credentials' => [
            'key' => env('BANK_OWNERSHIP_API_KEY'),
            'secret' => env('BANK_OWNERSHIP_API_SECRET'),
        ],
        'cache_ttl_days' => 180,
    ]),

    /*
    |----------------------------------------------------------------------
    | Guarantee — Sayad cheque inquiries, promissory notes
    |----------------------------------------------------------------------
    */
    'guarantee' => array_replace_recursive($defaults, [
        'driver' => env('GUARANTEE_DRIVER', 'fake'),
        'environment' => env('GUARANTEE_ENV', 'sandbox'),
        'base_url' => env('GUARANTEE_BASE_URL'),
        'credentials' => [
            'key' => env('GUARANTEE_API_KEY'),
            'secret' => env('GUARANTEE_API_SECRET'),
        ],

        // TODO(business) B6: which of CHEQUE-01..07 are mandatory before a
        // guarantee may be Verified. Empty means never auto-verify.
        //
        // Local testing seam only, same caveat as required_checks above:
        // VERIFICATION_GUARANTEE_REQUIRED_INQUIRIES=sayad_validate,ownership_match
        'required_inquiries' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('VERIFICATION_GUARANTEE_REQUIRED_INQUIRIES', ''))
        ))),

        // TODO(business) B5: how the guaranteed amount is derived (a multiple
        // of the deposit? of the device value?). null means the submitted
        // amount is recorded but no rule is applied.
        'amount_rule' => null,

        // TODO(business) B7: bounced-cheque / credit-risk rejection
        // thresholds. Empty means scores are recorded, never auto-rejected.
        'risk_thresholds' => [],

        // TODO(integration): App\Support\Guarantee\SayadId implements the
        // widely-published check-digit rule, but no bank specification is
        // available here to confirm it. While this is false, only the
        // structural rules (16 digits, not all identical) apply -- rejecting a
        // genuine cheque on an unverified checksum would be worse than letting
        // the provider reject a malformed id. Flip to true once confirmed.
        'enforce_sayad_checksum' => env('GUARANTEE_ENFORCE_SAYAD_CHECKSUM', false),

        'cache_ttl_days' => 30,
    ]),

    /*
    |----------------------------------------------------------------------
    | Signature — digital signature of the rental contract
    |----------------------------------------------------------------------
    |
    | The `internal` driver is an HMAC over the contract's content hash plus
    | the signer and timestamp, with SMS-OTP as the signer challenge. It proves
    | the stored contract has not been altered since signing and that the
    | signer held the phone. It is NOT PKI and carries no eIDAS-equivalent
    | legal weight -- a CA-backed provider is a TODO.
    */
    'signature' => array_replace_recursive($defaults, [
        'driver' => env('SIGNATURE_DRIVER', 'internal'),
        'environment' => env('SIGNATURE_ENV', 'sandbox'),
        'base_url' => env('SIGNATURE_BASE_URL'),
        'otp_ttl_minutes' => 5,
        'storage' => ['path' => 'contracts'],
    ]),

    /*
    |----------------------------------------------------------------------
    | SMS — the general "send a message" seam (SMS-02..SMS-06)
    |----------------------------------------------------------------------
    |
    | Separate from OTP delivery, which keeps its own
    | App\Services\Otp\OtpProviderInterface and its MELIPAYAMAK_* config in
    | config/services.php. That contract is different (the provider generates
    | the code and we never see the body) and authentication depends on it, so
    | it is not folded in here.
    |
    | Default `log` outside production, `unconfigured` in production: CLAUDE.md
    | forbids implementing live SMS behaviour before the owner confirms a
    | provider, so production fails loudly rather than pretending to send.
    */
    'sms' => array_replace_recursive($defaults, [
        'driver' => env('SMS_DRIVER', env('APP_ENV') === 'production' ? 'unconfigured' : 'log'),
        'environment' => env('SMS_ENV', 'sandbox'),
        'base_url' => env('SMS_BASE_URL'),
        'credentials' => [
            'key' => env('SMS_API_KEY'),
            'secret' => env('SMS_API_SECRET'),
        ],
        'rate_limit' => ['per_minute' => 120, 'per_user_per_day' => 20],
    ]),

    /*
    |----------------------------------------------------------------------
    | Media — secure storage of verification video and documents
    |----------------------------------------------------------------------
    */
    'media' => [
        'disk' => 'verification',
        'signed_url_minutes' => env('VERIFICATION_SIGNED_URL_MINUTES', 10),

        'max_size_kb' => [
            'national_card' => 5 * 1024,
            'selfie' => 5 * 1024,
            'liveness_video' => 50 * 1024,
            'handover_video' => 100 * 1024,
            'return_video' => 100 * 1024,
        ],
        'allowed_mimes' => [
            'national_card' => ['image/jpeg', 'image/png', 'image/webp'],
            'selfie' => ['image/jpeg', 'image/png', 'image/webp'],
            'liveness_video' => ['video/mp4', 'video/webm', 'video/quicktime'],
            'handover_video' => ['video/mp4', 'video/webm', 'video/quicktime'],
            'return_video' => ['video/mp4', 'video/webm', 'video/quicktime'],
        ],

        // TODO(business) B11: retention per kind, in days. A kind left null is
        // SKIPPED by verification:purge-media -- nothing is ever deleted on a
        // guessed policy.
        'retention_days' => [
            'national_card' => null,
            'selfie' => null,
            'liveness_video' => null,
            'handover_video' => null,
            'return_video' => null,
        ],
    ],
];
