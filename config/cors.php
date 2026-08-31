<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Published explicitly (BUG-099) so the app does not silently fall back
    | to Laravel's framework default of allowed_origins => ['*']. The API
    | is only ever called same-origin by this app's own frontend, so it is
    | restricted to the app's own configured URL rather than left wide open.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [config('app.url')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
