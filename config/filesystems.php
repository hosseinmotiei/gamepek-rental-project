<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
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
            'driver' => 'local',
            'root' => env('STORAGE_PUBLIC_PATH', public_path('storage')),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        // ──────────────────────────────────────────────────────────────────
        // Verification media: identity documents, liveness and handover video.
        //
        // Private, and deliberately NOT the `public` disk above -- that disk's
        // root is a real directory Apache serves straight out of public_html,
        // so anything written there is readable by anyone who guesses the URL.
        //
        // `serve` is also deliberately omitted (the default `local` disk sets
        // it to true): Laravel's own /storage/{path} serving must not expose
        // this disk either. The single way in is the signed, ownership-checked
        // `verification.media.show` route, and every hit is audited.
        // ──────────────────────────────────────────────────────────────────
        'verification' => [
            'driver' => 'local',
            'root' => env('VERIFICATION_STORAGE_PATH', storage_path('app/private/verification')),
            'visibility' => 'private',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
