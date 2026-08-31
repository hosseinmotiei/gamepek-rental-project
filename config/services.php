<?php

return [
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // OTP Provider (MeliPayamak is the sole OTP delivery channel)
    'melipayamak' => [
        'api_key' => env('MELIPAYAMAK_API_KEY'),
        'endpoint' => env('MELIPAYAMAK_ENDPOINT', 'https://console.melipayamak.com/api/send/otp'),
        'timeout' => env('MELIPAYAMAK_TIMEOUT', 10),
    ],

    // Payment Gateways
    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        'sandbox' => env('ZARINPAL_SANDBOX', true),
        'callback_url' => env('ZARINPAL_CALLBACK_URL'),
    ],

    'idpay' => [
        'api_key' => env('IDPAY_API_KEY'),
        'sandbox' => env('IDPAY_SANDBOX', true),
        'callback_url' => env('IDPAY_CALLBACK_URL'),
    ],
];
