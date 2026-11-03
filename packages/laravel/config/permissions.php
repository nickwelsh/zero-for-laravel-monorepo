<?php

declare(strict_types=1);

return [
    'connection' => null,
    'denied_status' => 403,
    // Server-only UX control watches; these never authorize native table reads.
    'access' => [
        'max_watches' => 32,
        'ttl_seconds' => 300,
        'stream_seconds' => 20,
        'poll_milliseconds' => 250,
        'heartbeat_seconds' => 5,
    ],
    // Advisory browser checks are opt-in; authority attributes stay private by default.
    'predictions' => [
        'abilities' => [],
        'subject_attributes' => [],
        'resource_attributes' => [],
    ],
    'models' => ['default' => __DIR__.'/permissions/scoped-rbac-abac.conf'],
    'subject' => [
        'class' => App\Models\User::class,
        'prefix' => 'user:',
        'attributes' => [],
    ],
    // Bind database columns, not PHP accessors or request values.
    'resources' => [],
];
