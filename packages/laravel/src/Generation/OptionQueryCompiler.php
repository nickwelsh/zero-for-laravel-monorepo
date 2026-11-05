<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Formbraid\Laravel\Field;
use Formbraid\Laravel\OptionInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use ReflectionMethod;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Formbraid\ZeroOptions;
use ZeroForLaravel\Generation\Mutations\MutationProgram;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\PolicyCompiler;

/** Compiles field-owned declarations through the same checked expression tree as page reads. */
final readonly class OptionQueryCompiler
{
    public function __construct(private ControllerQueryCompiler $compiler, private ModelRegistry $models) {}

    /** @return list<ControllerQuery> */
    public function compile(MutationProgram $mutation): array
    {
        $queries = [];
        foreach ($mutation->parameters as $parameter) {
            if ($parameter['kind'] !== 'request' || ! isset($parameter['type']) || ! method_exists($parameter['type'], 'fields')) {
                continue;
            }

            $class = $parameter['type'];
            if (! is_subclass_of($class, FormRequest::class)) {
                throw new LogicException('Option declarations require a FormRequest.');
            }

            $request = new $class;
            $request->setContainer(app());
            if (! method_exists($request, 'fields')) {
                continue;
            }

            $fields = $request->fields();
            if (! is_array($fields)) {
                throw new LogicException($class.'::fields() must return a field map.');
            }

            foreach ($fields as $field => $definition) {
                if (! is_string($field) || ! $definition instanceof Field) {
                    throw new LogicException($class.'::fields() must map field names to Field instances.');
                }

                $source = $definition->toArray()['options'] ?? null;
                if ($source === null || is_array($source)) {
                    continue;
                }

                if (! $source instanceof ZeroOptions) {
                    throw new LogicException($class.'.'.$field.': unsupported option source.');
                }

                foreach (['list', ...($source->resolve instanceof \ZeroForLaravel\Query\ZeroQuery ? ['resolve'] : [])] as $operation) {
                    $queries[] = $this->operation($mutation, $class, $field, $source, $operation);
                }
            }
        }

        return $queries;
    }

    private function operation(MutationProgram $mutation, string $request, string $field, ZeroOptions $source, string $operation): ControllerQuery
    {
        $expression = $this->compiler->formOption($request, $field, $operation);
        if ($expression->type !== 'query' || ($operation === 'list' && $expression->singular) || ($operation === 'resolve' && ! $expression->singular)) {
            throw new LogicException($request.'.'.$field.': listing requires rows; resolution requires one().');
        }

        if ($operation === 'list' && (! $expression->ordersKey() || ! $this->limited($expression))) {
            throw new LogicException($request.'.'.$field.': option lists require a fixed limit and deterministic key ordering.');
        }

        $references = [];
        foreach ($operation === 'list' ? $source->inputs() : $source->resolveInputs() as $input) {
            $references[json_encode($input->toArray(), JSON_THROW_ON_ERROR)] = $input;
        }

        $guards = [];
        foreach ($source->authorize as $name => $ability) {
            $parameter = $mutation->parameters[$name] ?? null;
            if (($parameter['kind'] ?? null) !== 'model' || ! isset($parameter['type']) || ! is_subclass_of($parameter['type'], Model::class) || $ability === '') {
                throw new LogicException($request.'.'.$field.': authorize must map model context names to read abilities.');
            }

            $guards[$name] = ['class' => $parameter['type'], 'ability' => $ability,
                'fingerprint' => (new PolicyCompiler)->compile($parameter['type'], $ability)->fingerprint];
            $input = OptionInput::context($name);
            $references[json_encode($input->toArray(), JSON_THROW_ON_ERROR)] = $input;
        }

        ksort($references);
        $parameters = [];
        $inputs = [];
        $contracts = [];
        $contextParameters = [];
        $replacements = [];
        $rules = RequestContract::rules($request);
        foreach ($references as $identity => $input) {
            $name = 'i'.count($inputs);
            $constraints = [];
            if ($input->kind === 'value') {
                if ($operation === 'resolve') {
                    throw new LogicException($request.'.'.$field.': resolution cannot depend on value().');
                }

                $type = $this->operandType($expression, $identity) ?? 'string';
                $constraints = $type === 'string' ? ['string', 'max:256'] : [$type === 'bool' ? 'boolean' : 'numeric'];
            } elseif ($input->kind === 'field') {
                $constraints = $rules[$input->path] ?? throw new LogicException($request.'.'.$field.': unknown dependency '.$input->path.'.');
                $type = RequestContract::type($constraints);
                $constraints = array_values(array_filter($constraints, static fn (string $rule): bool => in_array(explode(':', $rule, 2)[0], ['string', 'integer', 'numeric', 'boolean', 'uuid', 'ulid', 'min', 'max', 'in'], true)));
            } else {
                $context = $mutation->parameters[$input->key] ?? null;
                if ($context === null || ! isset($context['type']) || ! in_array($context['kind'], ['model', 'scalar'], true)) {
                    throw new LogicException($request.'.'.$field.': unknown route context '.$input->key.'.');
                }

                $contextParameters[$input->key] = ['kind' => $context['kind'], 'type' => $context['type']];
                if ($context['kind'] === 'model') {
                    if (! is_subclass_of($context['type'], Model::class)) {
                        throw new LogicException($request.'.'.$field.': context requires a model class.');
                    }

                    if (! isset($guards[$input->key])) {
                        throw new LogicException($request.'.'.$field.': model context '.$input->key.' requires an explicit authorize read ability.');
                    }

                    $type = (new $context['type'])->getKeyType() === 'int' ? 'int' : 'string';
                } else {
                    $type = $context['type'];
                }
            }

            if (! in_array($type, ['string', 'int', 'float', 'bool'], true)) {
                throw new LogicException($request.'.'.$field.': option inputs must be scalar.');
            }

            $expected = $this->operandType($expression, $identity);
            if ($expected !== null && $expected !== $type && ! in_array([$expected, $type], [['float', 'int'], ['int', 'float']], true)) {
                throw new LogicException($request.'.'.$field.': incompatible option input type for '.$identity.'.');
            }

            $parameters[$name] = $type;
            $inputs[$name] = $input->toArray();
            $contracts[$name] = ['kind' => $type, 'rules' => $constraints];
            $replacements[$identity] = new QueryExpression('argument', $name, type: $type);
        }

        $expression = $this->replace($expression, $replacements);
        $definition = ['request' => $request, 'field' => $field, 'id' => $request.':'.$field,
            'operation' => $operation, 'inputs' => $inputs, 'contracts' => $contracts,
            'context' => $contextParameters, 'authorize' => $source->authorize,
            'value' => $source->value, 'label' => $source->label];
        $models = $this->models->all();
        ksort($models);
        $schemas = array_map(static function (ModelMetadata $model): array {
            $schema = $model->toArray();
            // Relationship metadata is lazy; only this query's dependencies affect its identity.
            $schema['relations'] = [];
            $schema['relationConstraints'] = [];

            return $schema;
        }, $models);
        $fingerprint = hash('sha256', json_encode([$definition, $schemas, $guards, $this->compiler->dependencies($expression), ContextDefinition::configured()->contract(), $mutation->parameters], JSON_THROW_ON_ERROR));
        $reflection = new ReflectionMethod($request, 'fields');

        return new ControllerQuery($mutation->action, '', $definition['id'].':'.$operation, $parameters, $expression,
            $reflection->getFileName() ?: '', $reflection->getStartLine() ?: 0, $fingerprint, optionSource: $definition);
    }

    private function limited(QueryExpression $expression): bool
    {
        if ($expression->kind === 'infinite') {
            return is_int($expression->value) && $expression->value > 0 && $expression->value <= 100;
        }

        if ($expression->kind === 'method' && $expression->value === 'limit') {
            $limit = $expression->children[1];

            return $limit->kind === 'literal' && is_int($limit->value) && $limit->value > 0 && $limit->value <= 100;
        }

        return isset($expression->children[0]) && $this->limited($expression->children[0]);
    }

    private function operandType(QueryExpression $expression, string $identity): ?string
    {
        $types = [];
        if ($expression->kind === 'optionContains' && ($expression->children[0]->value ?? null) === $identity) {
            $types[] = 'string';
        }

        if ($expression->kind === 'method' && in_array($expression->value, ['where', 'orWhere', 'cmp'], true)) {
            $args = array_slice($expression->children, 1);
            $operand = end($args);
            if ($operand instanceof QueryExpression && $operand->kind === 'optionInput' && $operand->value === $identity && ($args[0]->kind ?? null) === 'literal') {
                $column = $expression->model?->columns[$args[0]->stringValue()] ?? null;
                if ($column !== null) {
                    $types[] = match ($column['type']) {
                        'number' => 'float', 'boolean' => 'bool', default => $column['type']
                    };
                }
            }
        }

        foreach ($expression->children as $child) {
            if (($type = $this->operandType($child, $identity)) !== null) {
                $types[] = $type;
            }
        }

        if (count(array_unique($types)) > 1) {
            throw new LogicException('Conflicting option input uses: '.$identity);
        }

        return $types[0] ?? null;
    }

    /** @param array<string, QueryExpression> $replacements */
    private function replace(QueryExpression $expression, array $replacements): QueryExpression
    {
        if ($expression->kind === 'optionInput') {
            return $replacements[$expression->stringValue()] ?? throw new LogicException('Undeclared option input.');
        }

        return new QueryExpression($expression->kind, $expression->value, array_map(fn (QueryExpression $child): QueryExpression => $this->replace($child, $replacements), $expression->children), $expression->model, $expression->type, $expression->singular);
    }
}
