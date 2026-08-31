<?php

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
