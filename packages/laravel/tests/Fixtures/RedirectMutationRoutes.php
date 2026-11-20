<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

final class RedirectMutationRoutes
{
    public const METHODS = ['store', 'confirmed', 'bound', 'slug', 'disabled', 'projected'];

    public static function register(): void
    {
        Route::get('/todos', fn (): string => 'index')->name('todos.index');
        Route::get('/todos/{todo}', fn (): string => 'show')->name('todos.show');
        Route::get('/title/{todo:title}', fn (): string => 'title')->name('todos.title');
        Route::post('/write', fn (): string => 'write')->name('todos.write');
        foreach (self::METHODS as $method) {
            Route::post('/submit/'.$method.($method === 'bound' ? '/{todo}' : ''), [RedirectMutationController::class, $method])->middleware(SubstituteBindings::class);
        }
    }
}
