<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pey (dev1/pey-laravel)
|--------------------------------------------------------------------------
|
| Unofficial Laravel integration for the Pey API. Not affiliated with Pey or
| Banco de México. Pey has no sandbox: every request moves real money.
|
| Every value is read from the environment. This file holds only scalars and
| arrays, so `php artisan config:cache` works. Nothing is validated while the
| application boots: a missing or invalid value throws a
| ConfigurationException when the Pey client (or the webhook verifier) is
| first used.
|
*/

return [

    // The API key from the Pey portal. It is wrapped in the SDK's Secret as
    // soon as it is read, and never appears in logs, exceptions or output.
    'api_key' => env('PEY_API_KEY'),

    // Pey's production API. Change it only if Pey releases another environment.
    'base_url' => env('PEY_BASE_URL', 'https://api.pey.mx'),

    // Seconds allowed to establish the connection, and for the whole request.
    'connect_timeout' => env('PEY_CONNECT_TIMEOUT', 5.0),
    'timeout' => env('PEY_TIMEOUT', 15.0),

    // Retries for GET requests only, after a network error, a 429 or a 503.
    // POST requests are never retried: a retry could create a second charge.
    'retry' => [
        'max_retries' => env('PEY_MAX_RETRIES', 2),
    ],

    // CLABEs this application may register with Pey, comma-separated.
    // Empty (the default) blocks every bank account registration.
    'allowed_clabes' => env('PEY_ALLOWED_CLABES', ''),

    'webhooks' => [

        // The whsec_… secret from the Pey portal. While rotating it, keep the
        // old one in PEY_WEBHOOK_SECRET_PREVIOUS until Pey uses the new one.
        'secrets' => [
            env('PEY_WEBHOOK_SECRET'),
            env('PEY_WEBHOOK_SECRET_PREVIOUS'),
        ],

        // How far a webhook's timestamp may be from now, in seconds.
        'tolerance' => env('PEY_WEBHOOK_TOLERANCE', 300),

        // Cache store that remembers webhook ids, so each delivery is handled
        // once. Null uses the default store. It must be shared by every worker
        // and server (for example redis, database or memcached).
        'dedup_store' => env('PEY_WEBHOOK_DEDUP_STORE'),

        // How long a webhook id is remembered, in seconds (72 hours).
        'dedup_ttl' => env('PEY_WEBHOOK_DEDUP_TTL', 259_200),
    ],

    'idempotency' => [

        // Cache store for idempotency records. Null uses the default store. It
        // must support atomic locks and must not evict records early.
        'store' => env('PEY_IDEMPOTENCY_STORE'),
    ],

    // Log channel for the SDK. Null uses the default channel.
    'log_channel' => env('PEY_LOG_CHANNEL'),

];
