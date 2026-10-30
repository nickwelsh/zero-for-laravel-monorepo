<?php

declare(strict_types=1);

namespace ZeroForLaravel\Inertia;

use Illuminate\Support\ServiceProvider;
use LogicException;
use ZeroForLaravel\Query\RegisteredQueries;

/** Install once, before resolving Inertia's response factory. */
final class ZeroInertiaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(\Inertia\ServiceProvider::class);
        if ($this->app->resolved(\Inertia\ResponseFactory::class)) {
            throw new LogicException('Register ZeroInertiaServiceProvider before resolving Inertia.');
        }

        $this->app->singleton(\Inertia\ResponseFactory::class, ResponseFactory::class);
        $this->app->afterResolving(\Illuminate\Foundation\Http\FormRequest::class, function (\Illuminate\Foundation\Http\FormRequest $form): void {
            request()->attributes->set('zero.form.'.$form::class, $form);
        });
        $this->app->singleton(RegisteredQueries::class, fn (): RegisteredQueries => RegisteredQueries::load(config()->string('zero.controller_output')));
    }
}
