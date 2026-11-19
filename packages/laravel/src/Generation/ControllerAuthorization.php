<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use ReflectionMethod;

/** Only the explicit, positional Laravel authorization API has a portable meaning. */
final class ControllerAuthorization
{
    public static function matches(Expr $expression): bool
    {
        return $expression instanceof Expr\MethodCall && $expression->var instanceof Expr\Variable
            && $expression->var->name === 'this' && $expression->name instanceof Identifier && $expression->name->name === 'authorize';
    }

    public static function gate(Expr\MethodCall $expression, string $controller): Expr\StaticCall
    {
        foreach (['authorize', 'parseAbilityAndArguments'] as $method) {
            if (! method_exists($controller, $method)) {
                throw new LogicException($controller.': authorization requires Laravel AuthorizesRequests.');
            }

            $actual = new ReflectionMethod($controller, $method);
            $expected = new ReflectionMethod(AuthorizesRequests::class, $method);
            if ($actual->getFileName() !== $expected->getFileName() || $actual->getStartLine() !== $expected->getStartLine()) {
                throw new LogicException($controller.': custom authorize or parseAbilityAndArguments implementations are not portable.');
            }
        }

        self::arguments($expression);

        return new Expr\StaticCall(new Name\FullyQualified(Gate::class), 'authorize', $expression->getArgs(), $expression->getAttributes());
    }

    public static function arguments(Expr\MethodCall|Expr\StaticCall $expression): void
    {
        $args = $expression->getArgs();
        if (count($args) !== 2 || ! $args[0]->value instanceof String_ || $args[0]->value->value === '' || str_contains($args[0]->value->value, '\\')) {
            throw new LogicException('Authorization requires an explicit literal ability and resource.');
        }

        foreach ($args as $arg) {
            if ($arg->name !== null || $arg->unpack || $arg->byRef) {
                throw new LogicException('Authorization arguments must be positional.');
            }
        }
    }
}
