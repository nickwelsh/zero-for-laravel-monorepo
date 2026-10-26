<?php

declare(strict_types=1);

namespace ZeroForLaravel\Domain;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use ZeroForLaravel\Support\Input;
use ZeroForLaravel\ZeroContext;

final class DomainRegistry
{
    /** @var array<string, class-string<DomainMutation>> */
    private array $operations = [];

    /** @param array<mixed> $operations */
    public function __construct(array $operations = [])
    {
        foreach ($operations as $name => $class) {
            if (! is_string($name) || ! is_string($class) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $name) || ! is_subclass_of($class, DomainMutation::class)) {
                throw new LogicException('Domain mutations require explicit operation names and DomainMutation classes.');
            }

            if (! is_subclass_of($class::request(), FormRequest::class) || ! method_exists($class::request(), 'authorize')) {
                throw new LogicException('Domain mutations require a FormRequest with explicit authorize().');
            }

            foreach ($class::input() as $field => $spec) {
                if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $field) || ! in_array($spec['type'], ['string', 'number', 'boolean'], true) || (($spec['array'] ?? false) && (($spec['maxItems'] ?? 0) < 1 || $spec['maxItems'] > 100))) {
                    throw new LogicException('Domain inputs support scalar fields and explicitly bounded scalar arrays (1–100 items).');
                }
            }

            $this->operations[$name] = $class;
        }
    }

    /** @return array<string, array<string, array{type: 'string'|'number'|'boolean', optional?: bool, nullable?: bool, array?: bool, maxItems?: int}>> */
    public function contracts(): array
    {
        return array_map(fn (string $class): array => $class::input(), $this->operations);
    }

    public function execute(string $name, mixed $arguments, ZeroContext $context, ?Authenticatable $user): void
    {
        $operation = substr($name, strlen('actions.'));
        $class = $this->operations[$operation] ?? null;
        if ($class === null || ! str_starts_with($name, 'actions.')) {
            Input::fail('name', 'Unknown domain mutation.');
        }

        $input = $this->validate($class, $arguments);
        $requestClass = $class::request();
        $request = FormRequest::createFrom(resolve('request'), new $requestClass);
        $request->setContainer(app())->setRedirector(resolve('redirect'));
        $request->query->replace([]);
        $request->files->replace([]);
        $request->replace($input);
        $request->setUserResolver(fn (): ?Authenticatable => $user);
        $request->attributes->set(ZeroContext::class, $context);
        $request->validateResolved();
        // FormRequest normalization must still obey the published wire contract.
        $input = $this->validate($class, $request->validated());
        $action = resolve($class);
        if (! $action instanceof DomainMutation) {
            throw new LogicException('Invalid domain action binding.');
        }

        $action->handle($input, $context);
    }

    /** @param class-string<DomainMutation> $class
     * @return array<string, mixed>
     */
    private function validate(string $class, mixed $arguments): array
    {
        $input = Input::object($arguments, 'input');
        Input::keys($input, array_keys($class::input()), 'input');
        foreach ($class::input() as $field => $spec) {
            if (! array_key_exists($field, $input)) {
                if ($spec['optional'] ?? false) {
                    continue;
                }

                Input::fail($field, 'This field is required.');
            }

            $value = $input[$field];
            if ($value === null && ($spec['nullable'] ?? false)) {
                continue;
            }

            $values = [$value];
            if ($spec['array'] ?? false) {
                if (! is_array($value) || ! array_is_list($value) || count($value) > ($spec['maxItems'] ?? 0)) {
                    Input::fail($field, 'Expected a bounded list.');
                }

                $values = $value;
            }

            foreach ($values as $item) {
                $valid = match ($spec['type']) {
                    'string' => is_string($item) && strlen($item) <= 65536,
                    'boolean' => is_bool($item),
                    'number' => (is_int($item) || is_float($item)) && is_finite((float) $item) && abs($item) <= 9007199254740991,
                };
                if (! $valid) {
                    Input::fail($field, 'Invalid domain input type.');
                }
            }
        }

        return $input;
    }
}
