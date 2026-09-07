<?php

use App\Services\Payment\Gateways\PardakhtNovinAdapter;

return [
    'otp' => [
        'expiry_minutes' => env('AUTH_OTP_EXPIRY_MINUTES', 2),
        'rate_limit' => env('AUTH_OTP_RATE_LIMIT', 5),
        'rate_limit_decay_minutes' => env('AUTH_OTP_RATE_LIMIT_DECAY_MINUTES', 15),
        'show_in_dev' => env('OTP_SHOW_IN_DEV', false),
        'length' => env('OTP_LENGTH', 5),
    ],

    'payment' => [
        'gateway' => env('PAYMENT_GATEWAY', 'mock'),
        'callback_url' => env('PAYMENT_CALLBACK_URL'),

        // Gateway key => adapter class, resolved by
        // App\Services\Payment\GatewayRegistry. A key that is absent here, or
        // whose class does not implement PaymentGatewayInterface, resolves to
        // UnconfiguredGateway -- which fails closed with a Persian message.
        // `mock` is deliberately NOT listed: the registry hands it out only in
        // local/testing, so a PAYMENT_GATEWAY typo can never run mock in
        // production. Zarinpal and IDPay are likewise absent -- they were
        // never implemented, only stubbed, and now answer through
        // UnconfiguredGateway with the same Persian message as before.
        'gateways' => [
            'pardakhtnovin' => PardakhtNovinAdapter::class,
        ],

        // PAY-06 reconciliation: a transaction still `pending` this long after
        // creation is inspected by `php artisan payments:reconcile`.
        'reconcile' => [
            'stale_after_minutes' => env('PAYMENT_RECONCILE_STALE_MINUTES', 30),
            'lookback_hours' => env('PAYMENT_RECONCILE_LOOKBACK_HOURS', 72),
        ],

        // Pardakht Novin IPG (NormalSale/Confirm/Reverse), carried over from
        // the Store. Per the official docs (I.P.IT.012.00), only
        // CorporationPin identifies the merchant in the request bodies --
        // terminal_id and merchant_id are not sent as request fields and are
        // kept here only for support reference against the merchant
        // credentials document.
        //
        // No live gateway is connected to the Rental platform. Leave
        // PAYMENT_GATEWAY=mock until the owner confirms rental-specific
        // merchant credentials have been obtained.
        'pardakhtnovin' => [
            'corporation_pin' => env('PARDKHTNOVIN_CORPORATION_PIN'),
            'terminal_id' => env('PARDKHTNOVIN_TERMINAL_ID'),
            'merchant_id' => env('PARDKHTNOVIN_MERCHANT_ID'),
            'callback_url' => env('PARDKHTNOVIN_CALLBACK_URL'),
            'sandbox' => env('PARDKHTNOVIN_SANDBOX', false),
        ],
    ],

    'cart' => [
        'max_quantity_per_item' => 10,
        'guest_session_key' => 'guest_cart_id',
    ],

    'pricing' => [
        // Duration discount tiers, read by RentalPricingService.
        //
        // Keyed by minimum rental length in days => discount fraction. The
        // longest qualifying tier wins, so declaration order does not matter.
        //
        // These values are carried over from the `Grok-show` rental
        // prototype, where they drove a price preview. They are NOT a
        // confirmed commercial policy — rental pricing has not been signed
        // off by the owner. Treat them as a placeholder to be replaced, and
        // note that the deposit is deliberately not discounted: it is a
        // refundable hold, not a charge.
        'duration_discounts' => [
            7 => 0.05,
            14 => 0.10,
            30 => 0.15,
        ],
    ],

    'reservation' => [
        // TODO(business) B10: how long a held reservation survives before it
        // expires. null means holds never auto-expire and the expiry sweep
        // no-ops -- a customer's reservation is never dropped on a guessed
        // timeout.
        'hold_minutes' => env('RENTAL_HOLD_MINUTES'),
    ],

    /*
     * The post-approval lifecycle: Approved -> Active -> Returned -> Closed.
     *
     * TODO(business) B14. Every trigger below is null because the project
     * defines none of them:
     *
     *   activation -- does a rental become Active on handover, on the
     *                 reservation's start date, or on an admin's action?
     *   return     -- who confirms a return, and does it require the
     *                 return_video, an inspection, or a damage assessment?
     *   closure    -- closure plausibly waits on the deposit being released
     *                 (B4) and on media retention (B11), both undecided.
     *
     * While a trigger is null RentalChainOrchestrator::transitionPostApproval()
     * refuses the transition and records `rental_application.policy_undefined`.
     * Nothing derives these states, and no route exposes them.
     */
    'lifecycle' => [
        'activation_trigger' => null,
        'return_trigger' => null,
        'closure_trigger' => null,
    ],

    'contract' => [
        // Which contract_templates key ContractService::generate() renders.
        'template_key' => env('RENTAL_CONTRACT_TEMPLATE_KEY', 'rental_agreement'),
    ],

    'sms' => [
        'max_attempts' => env('RENTAL_SMS_MAX_ATTEMPTS', 3),

        // SMS-05. Template key => body, with {{placeholder}} substitution.
        //
        // DELIBERATELY EMPTY. No customer-facing SMS copy has been approved by
        // the owner, and writing Persian transactional copy on their behalf is
        // not this code's call (TODO(business) B13). SmsService sends nothing
        // for an unknown key and records `sms.template_undefined` in the audit
        // trail, so the gap is visible rather than silent.
        'templates' => [],

        // Chain transition => template key. Also empty, for the same reason:
        // no SMS fires on any state change until the copy exists.
        'state_templates' => [],
    ],

    'pagination' => [
        'products_per_page' => 20,
        'orders_per_page' => 10,
    ],

    'admin' => [
        // Single source of truth for which Spatie roles may access the admin
        // panel. Used by both EnsureIsAdmin (route middleware) and
        // AdminLoginController (login gate) so the two can never drift.
        'roles' => ['super_admin', 'admin', 'product_manager', 'order_manager', 'content_manager', 'support'],
    ],

    'search' => [
        // Cities the home-page rental search offers. Not invented data: the
        // app already restricts delivery to Tehran (see StoreAddressRequest's
        // `city.in:تهران` rule), so this list mirrors that single real
        // constraint. Add a city here only when delivery to it actually
        // exists -- and update StoreAddressRequest in the same change.
        'cities' => ['تهران'],

        // Longest rental window the search form accepts, in days.
        'max_days' => 90,
    ],

    'catalog' => [
        // Listing-page filter facets, read by CatalogService.
        //
        // Each key is matched against the item's `attributes` JSON column, so
        // adding a facet needs no migration. This list is deliberately empty:
        // the Store's facets (region, capacity, product type) were
        // PlayStation-retail concepts, and the rental facets (device
        // condition, generation, included accessories, …) are a rental domain
        // decision that has not been made yet.
        //
        // Shape when populated:
        //   'condition' => [
        //       'label'   => 'وضعیت دستگاه',
        //       'options' => ['new' => 'نو', 'used' => 'کارکرده'],
        //   ],
        //
        // A category can narrow or extend this set through its own `filters`
        // JSON column, editable from the admin panel.
        'facets' => [],
    ],
];
