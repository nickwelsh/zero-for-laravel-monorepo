<?php

use Formbraid\Laravel\Drivers\Fetch;

return [
    'requests' => ['app/Http/Requests'],
    'metadata' => 'resources/js/formbraid/_metadata.json',
    'output' => 'resources/js/formbraid/laravel',
    'component_enum' => 'app/Formbraid/FormField.php',
    // Ordinary HTTP forms can use Formbraid's Laravel producer; Zero forms use zero:formbraid.
    'submission_driver' => Fetch::class,
    'fields' => [
        'types' => ['string' => 'TextField', 'boolean' => 'CheckboxField'],
        'rules' => ['email' => ['component' => 'TextField', 'props' => ['type' => 'email']]],
        'fallbackType' => 'string',
    ],
];
