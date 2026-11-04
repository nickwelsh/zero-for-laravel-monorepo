<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\Mutations\ValueContract;
use ZeroForLaravel\Generation\OptionQueryCompiler;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/**
 * An authenticated read, deliberately independent of mutation request execution.
 *
 * @phpstan-import-type OptionMetadata from \ZeroForLaravel\Generation\ControllerQuery
 */
final readonly class RegisteredOptionQuery
{
    /**
     * @param  array<string, string>  $parameters
     * @param  Closure(array<string, mixed>): ZeroQuery  $factory
     * @param  OptionMetadata  $source
     */
    public function __construct(
        public string $action,
        public array $parameters,
        private Closure $factory,
        public array $source,
        public string $contract,
    ) {}

    public function assertCurrent(): void
    {
        [$class, $method] = explode('@', $this->action, 2);
        $mutation = resolve(ControllerMutationCompiler::class)->compile($class, $method);
        foreach (resolve(OptionQueryCompiler::class)->compile($mutation) as $query) {
            if ($query->name() === $this->contract) {
                return;
            }
        }

        throw new LogicException('Stale Zero option registration. Regenerate and deploy paired contracts.');
    }

    /** @return array<string, mixed> */
    public function invoke(mixed $arguments, ResolvedContext $identity, QueryCompiler $compiler): array
    {
        $args = Input::object($arguments, 'args');
        Input::keys($args, [...array_keys($this->parameters), '__generation'], 'args');
        if (isset($args['__generation']) && (! is_string($args['__generation']) || ! Str::isUuid($args['__generation']))) {
            Input::fail('generation', 'Query generations must be UUIDs.');
        }

        unset($args['__generation']);
        $rules = [];
        foreach ($this->parameters as $name => $type) {
            QueryInput::validate($type, $args[$name] ?? null, $name);
            $contract = $this->source['contracts'][$name];
            $rules[$name] = array_values(array_filter($contract['rules'], static fn (string $rule): bool => ! str_starts_with($rule, 'choices:')));
            $choices = (new ValueContract($contract['kind'], rules: $contract['rules']))->choices();
            if ($choices !== null) {
                $rules[$name][] = Rule::in($choices);
            }
        }

        Validator::make($args, $rules)->validate();

        $context = ContextDefinition::configured()->resolve(request());
        if ($context->userId() === null || $context->userId() !== $identity->userId()) {
            throw new AuthorizationException('Option identity differs from the authenticated Zero identity.');
        }

        $this->assertCurrent();
        $guards = [];
        foreach ($this->source['authorize'] as $name => $ability) {
            $class = $this->source['context'][$name]['type'];
            if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                throw new LogicException('Option read guard requires a model.');
            }

            $key = array_search(['kind' => 'context', 'key' => $name], $this->source['inputs'], true);
            if ($key === false) {
                throw new LogicException('Missing option read-guard input.');
            }

            // Form factories capture canonical IDs, not browser-supplied route slugs.
            $model = (new $class)->newQuery()->whereKey($args[$key] ?? null)->firstOrFail();
            Gate::forUser(request()->user())->authorize($ability, $model);
            $guards[] = ['class' => $class, 'id' => Binding::id($model->getKey()), 'ability' => $ability];
        }

        return $compiler->compileDeclaration(($this->factory)($args), $context, $guards, serving: true);
    }
}
