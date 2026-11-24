<?php

declare(strict_types=1);
use App\Models\User;

return [
    'connection' => null,
    'denied_status' => 403,
    // Native status checks never authorize business-table reads.
    'access' => [
        'max_bindings' => 32,
        // Model class => literal policy abilities exposed by usePermission.
        'abilities' => [],
    ],
    // Advisory browser checks are opt-in; authority attributes stay private by default.
    'predictions' => [
        'middleware' => ['web', 'auth'],
        'abilities' => [],
        'subject_attributes' => [],
        'resource_attributes' => [],
    ],
    'models' => ['default' => __DIR__.'/permissions/scoped-rbac-abac.conf'],
    'subject' => [
        'class' => User::class,
        'prefix' => 'user:',
        'attributes' => [],
    ],
    // Bind database columns, not PHP accessors or request values. Scoped class
    // checks also require domain.context naming an application-resolved Zero field.
    'resources' => [],
];
