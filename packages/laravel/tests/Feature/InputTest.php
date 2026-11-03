<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

test('objects reject numeric property names', function (): void {
    Input::object((object) ['12' => 'value'], 'args');
})->throws(ValidationException::class, 'Must use string field names.');

test('trusted context rejects non scalar field values', function (): void {
    (new ResolvedContext(['user_id' => []]))->get('user_id');
})->throws(AuthorizationException::class);

test('protocol identity is independent of application context fields', function (): void {
    expect((new ResolvedContext(['user_id' => 'alice']))->userId())->toBeNull();
    expect((new ResolvedContext(['user_id' => 'alice'], 'bob'))->userId())->toBe('bob');
});
