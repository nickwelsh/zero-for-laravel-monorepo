<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\ValidatedInput;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/** Laravel's except() removes keys; keep Larastan's inferred validated key/value types.
 * Standalone ValidatedInput objects can have numeric keys and are left untouched.
 */
final class FormRequestExceptReturnType implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return ValidatedInput::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'except';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $safe = $methodCall->var;
        if (! $safe instanceof MethodCall || ! $safe->name instanceof Identifier || $safe->name->name !== 'safe' || $safe->args !== []
            || ! (new ObjectType(FormRequest::class))->isSuperTypeOf($scope->getType($safe->var))->yes()) {
            return null;
        }

        $input = $scope->getType(new MethodCall($safe->var, 'validated'));

        return new ArrayType($input->getIterableKeyType(), $input->getIterableValueType());
    }
}
