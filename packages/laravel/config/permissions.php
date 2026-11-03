<?php

declare(strict_types=1);

return [
    'connection' => null,
    'denied_status' => 403,
    'models' => ['default' => __DIR__.'/permissions/scoped-rbac-abac.conf'],
    'subject' => [
        'class' => App\Models\User::class,
        'prefix' => 'user:',
        'attributes' => [],
    ],
    // Bind database columns, not PHP accessors or request values.
    'resources' => [],
];
