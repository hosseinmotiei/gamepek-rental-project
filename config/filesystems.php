<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app/private'),
            'serve'  => true,
            'throw'  => false,
        ],

        'public' => [
            // On this host, the domain's actual document root is public_html,
            // a directory ABOVE the Laravel app root (public_html/gamepek-backend).
            // public_html/storage is a real, separately-served folder -- NOT
            // the same as gamepek-backend/public/storage. Apache serves
            // gamepek.com/storage/* straight out of public_html/storage, so
            // this disk's root MUST point there, or uploads silently land
            // somewhere the web server never looks. STORAGE_PUBLIC_PATH must
            // be set to that absolute path in production's .env. Falls back
            // to public_path('storage') for any environment (e.g. local dev)
            // where the app root and the web root are the same directory.
            'driver'     => 'local',
            'root'       => env('STORAGE_PUBLIC_PATH', public_path('storage')),
            'url'        => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw'      => false,
        ],

        's3' => [
            'driver'   => 's3',
            'key'      => env('AWS_ACCESS_KEY_ID'),
            'secret'   => env('AWS_SECRET_ACCESS_KEY'),
            'region'   => env('AWS_DEFAULT_REGION'),
            'bucket'   => env('AWS_BUCKET'),
            'url'      => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw'    => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
