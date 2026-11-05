<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server API token
    |--------------------------------------------------------------------------
    |
    | Found in Haskimail: Server → "API-ключи". Use "HASKIMAIL_API_TEST" to
    | validate requests without sending anything.
    |
    | Precedence for every option: the mailer entry in config/mail.php, then
    | services.haskimail, then this file.
    |
    */

    'token' => env('HASKIMAIL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Default message stream
    |--------------------------------------------------------------------------
    |
    | Stream (channel) ID used when a message does not choose one. Leave empty
    | to use the server's default transactional stream.
    |
    */

    'message_stream' => env('HASKIMAIL_MESSAGE_STREAM'),

    'endpoint' => env('HASKIMAIL_ENDPOINT', 'https://api.haskimail.ru'),

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    |
    | A request is repeated automatically only when it certainly was not
    | processed: HTTP 429/503, ErrorCode 36/100, or a connection that could
    | not be established. HTTP 500, 502/504 and read timeouts are not
    | repeated, because the message may already have been accepted and the API
    | has no idempotency keys. Total waiting is capped at 20 seconds.
    |
    */

    'timeout' => (float) env('HASKIMAIL_TIMEOUT', 30),

    'connect_timeout' => (float) env('HASKIMAIL_CONNECT_TIMEOUT', 10),

    'retries' => (int) env('HASKIMAIL_RETRIES', 2),

    'retry_delay' => (int) env('HASKIMAIL_RETRY_DELAY', 200), // milliseconds, doubled on every attempt

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | When enabled, a POST route is registered that verifies incoming event
    | webhooks and dispatches Laravel events (MessageDelivered, MessageBounced,
    | ...). At least one authentication method must be configured:
    |
    |  - signing_key: the webhook signing key from Server → Channel → "Вебхуки".
    |    Every event's "secret" field is verified against it.
    |  - username / password: HTTP Basic credentials embedded in the webhook URL
    |    (https://user:pass@example.com/haskimail/webhook).
    |
    | If both are configured, both must pass. Configure both: the signature
    | covers only the recipient and message ID, not the event type.
    |
    */

    'webhooks' => [
        'enabled' => (bool) env('HASKIMAIL_WEBHOOKS_ENABLED', false),

        'path' => env('HASKIMAIL_WEBHOOK_PATH', 'haskimail/webhook'),

        'signing_key' => env('HASKIMAIL_WEBHOOK_SIGNING_KEY'),

        'username' => env('HASKIMAIL_WEBHOOK_USERNAME'),

        'password' => env('HASKIMAIL_WEBHOOK_PASSWORD'),

        // Extra middleware, run before authentication, e.g. ['throttle:600,1'].
        'middleware' => [],

        // Haskimail waits at most 10 seconds and never retries a timeout. With the
        // queue enabled the endpoint answers at once and listeners run in a job.
        'queue' => [
            'enabled' => (bool) env('HASKIMAIL_WEBHOOK_QUEUE', false),
            'connection' => env('HASKIMAIL_WEBHOOK_QUEUE_CONNECTION'),
            'queue' => env('HASKIMAIL_WEBHOOK_QUEUE_NAME'),
        ],

        // Haskimail may deliver an event more than once; duplicates are dropped
        // using the cache for "ttl" seconds (retries span about one hour).
        'deduplicate' => [
            'enabled' => (bool) env('HASKIMAIL_WEBHOOK_DEDUPLICATE', true),
            'store' => env('HASKIMAIL_WEBHOOK_CACHE_STORE'),
            'ttl' => 7200,
        ],
    ],

];
