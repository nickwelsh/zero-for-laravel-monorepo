<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use LogicException;
use ZeroForLaravel\Attributes\ResolvesContext;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

test('context generation reflects required scalar keys without resolving runtime services', function (): void {
    $definition = new ContextDefinition(ApplicationContext::class, ApplicationContextResolver::class);
    expect($definition->typescript())->toBe('{readonly "actor": string; readonly "enabled": boolean; readonly "ratio": number | null; readonly "tenant": number;}');
    expect(ApplicationContextResolver::$calls)->toBe(0);
    expect(fn (): ContextDefinition => new ContextDefinition(ApplicationContext::class, MissingContextResolver::class))->toThrow(LogicException::class, 'Missing');
    expect(fn (): ContextDefinition => new ContextDefinition(ApplicationContext::class, DuplicateContextResolver::class))->toThrow(LogicException::class, 'duplicate');
    expect(fn (): ContextDefinition => new ContextDefinition(ApplicationContext::class, UnsupportedContextReturnResolver::class))->toThrow(LogicException::class, 'returning');
});

test('context snapshots use request dependencies and keep transport identity separate from field names', function (): void {
    ApplicationContextResolver::$calls = 0;
    app()->instance(ContextFlag::class, new ContextFlag);
    $definition = new ContextDefinition(ApplicationContext::class, ApplicationContextResolver::class);
    $request = Request::create('/tenants/7');
    $request->setUserResolver(fn (): GenericUser => new GenericUser(['id' => 23]));
    $request->attributes->set('actor', 'alice');
    $request->attributes->set('tenant', 7);

    $first = $definition->resolve($request);
    $request->attributes->set('actor', 'bob');
    $request->attributes->set('tenant', 11);

    $second = $definition->resolve($request);
    expect($first->values)->toBe(['actor' => 'alice', 'enabled' => true, 'ratio' => null, 'tenant' => 7]);
    expect($second->values['actor'])->toBe('bob')->and($second->values['tenant'])->toBe(11);
    expect($first->userId())->toBe('23')->and($second->userId())->toBe('23');
    expect(ApplicationContextResolver::$calls)->toBe(2);
    $valid = $first->values;
    foreach ([array_diff_key($valid, ['ratio' => true]), [...$valid, 'extra' => true], [...$valid, 'tenant' => 9007199254740992], [...$valid, 'tenant' => '7'], [...$valid, 'ratio' => INF], [...$valid, 'actor' => null]] as $invalid) {
        expect(fn () => $definition->validate($invalid))->toThrow(LogicException::class);
    }
});

enum ApplicationContext: string
{
    case ACTOR = 'actor';
    case TENANT = 'tenant';
    case ENABLED = 'enabled';
    case RATIO = 'ratio';
}

class ContextFlag
{
    public bool $enabled = true;
}

class ApplicationContextResolver
{
    public static int $calls = 0;

    #[ResolvesContext(ApplicationContext::ACTOR)]
    public function actor(Request $request): string
    {
        self::$calls++;

        return $request->attributes->getString('actor');
    }

    #[ResolvesContext(ApplicationContext::TENANT)]
    public function tenant(Request $request): int
    {
        return $request->attributes->getInt('tenant');
    }

    #[ResolvesContext(ApplicationContext::ENABLED)]
    public function enabled(ContextFlag $flag): bool
    {
        return $flag->enabled;
    }

    #[ResolvesContext(ApplicationContext::RATIO)]
    public function ratio(): ?float
    {
        return null;
    }
}

class MissingContextResolver {}

class DuplicateContextResolver extends ApplicationContextResolver
{
    #[ResolvesContext(ApplicationContext::ACTOR)]
    public function duplicate(): string
    {
        return 'duplicate';
    }
}

class UnsupportedContextReturnResolver
{
    /** @return list<never> */
    #[ResolvesContext(ApplicationContext::ACTOR)]
    public function unsupported(): array
    {
        return [];
    }
}
