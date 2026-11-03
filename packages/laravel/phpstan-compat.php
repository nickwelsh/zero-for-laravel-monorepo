<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// Scope gained a class-level model template in Laravel 13.5.
return ['parameters' => ['stubFiles' => version_compare(Application::VERSION, '13.5.0', '<')
    ? [__DIR__.'/phpstan/visible-to-current-user.stub'] : []]];
