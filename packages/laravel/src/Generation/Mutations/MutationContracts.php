<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation\Mutations;

use LogicException;
use ZeroForLaravel\Generation\RequestContract;

/** Infer only proven shapes. Unknown output never becomes an `any` schema. */
final class MutationContracts
{
    public function server(MutationProgram $program): ValueContract
    {
        $contract = $program->serverResult === null
            ? $this->output($program->operations, $program->returnType, $this->input($program->parameters))
            : ValueContract::parse($program->serverResult);
        if (($program->returnType === 'void') !== ($contract->kind === 'void') || ($program->returns && $contract->includesVoid())) {
            throw new LogicException('The JSON result contract must preserve the declared void/data distinction.');
        }

        return $contract;
    }

    /** @param array<string, array{kind:string, type?:string}> $parameters */
    public function input(array $parameters): ValueContract
    {
        $fields = [];
        $requests = 0;
        foreach ($parameters as $parameter) {
            if ($parameter['kind'] !== 'request') {
                continue;
            }

            if (++$requests > 1) {
                throw new LogicException('Controller mutations support one FormRequest input contract.');
            }

            $fields = RequestContract::value($parameter['type'] ?? '')->fields;
        }

        return new ValueContract('object', fields: $fields);
    }

    /** @param list<array<string,mixed>> $operations */
    public function output(array $operations, string $declared, ValueContract $input): ValueContract
    {
        if (in_array($declared, ['void', 'null', 'string', 'int', 'bool', 'float'], true)) {
            return new ValueContract($declared === 'float' ? 'number' : $declared);
        }

        $locals = [];
        $results = [];
        $complete = $this->block(array_map(OperationNode::from(...), $operations), $locals, $results, $input);
        if (! $complete || $results === []) {
            throw new LogicException('Mutation result shape is not proven on every path; declare a supported result contract.');
        }

        $unique = [];
        foreach ($results as $result) {
            $unique[$result->typescript()] = $result;
        }

        return count($unique) === 1 ? reset($unique) : new ValueContract('union', members: array_values($unique));
    }

    /**
     * @param  list<OperationNode>  $operations
     * @param  array<string,ValueContract>  $locals
     * @param  list<ValueContract>  $results
     */
    private function block(array $operations, array &$locals, array &$results, ValueContract $input): bool
    {
        foreach ($operations as $operation) {
            $op = $operation->string('op');
            if ($op === 'set') {
                // Model/request/context locals retain compiler type metadata; their
                // properties are inferred at the get node, not serialized as objects.
                $value = $operation->node('value');
                if (in_array($value->string('op'), ['create', 'reload', 'findUnique', 'insertUnlessExists', 'upsertUnique'], true)) {
                    continue;
                }

                $locals[$operation->string('name')] = $this->expression($value, $locals, $input);
            } elseif ($op === 'return') {
                $results[] = $this->expression($operation->node('value'), $locals, $input);

                return true;
            } elseif ($op === 'if') {
                $yes = $locals;
                $no = $locals;
                $then = $this->block($operation->nodes('then'), $yes, $results, $input);
                $else = $this->block($operation->nodes('else'), $no, $results, $input);
                if ($then && $else) {
                    return true;
                }

                $locals = array_intersect_key($yes, $no);
                foreach ($locals as $key => $value) {
                    if ($value !== $no[$key]) {
                        $locals[$key] = new ValueContract('union', members: [$value, $no[$key]]);
                    }
                }
            }
        }

        return false;
    }

    /** @param array<string,ValueContract> $locals */
    private function expression(OperationNode $node, array $locals, ValueContract $input): ValueContract
    {
        $op = $node->string('op');
        if ($op === 'literal') {
            return new ValueContract(match (true) {
                $node->value('value') === null => 'null', is_string($node->value('value')) => 'string', is_bool($node->value('value')) => 'bool', is_int($node->value('value')) => 'int', is_float($node->value('value')) => 'number', default => throw new LogicException('Unknown result literal.'),
            });
        }

        if ($op === 'validated') {
            return $input;
        }

        if ($op === 'local') {
            return $locals[$node->string('name')] ?? throw new LogicException('Unproven local result '.$node->string('name'));
        }

        if ($op === 'get') {
            if ($node->value('result') !== null && $node->node('result')->string('kind') === 'value') {
                $result = $node->node('result');

                return new ValueContract($result->string('type') === 'boolean' ? 'bool' : $result->string('type'), nullable: $result->value('nullable') === true);
            }

            $base = $this->expression($node->node('base'), $locals, $input);
            $key = $node->node('key')->value('value');
            if (! is_string($key) || ! isset($base->fields[$key])) {
                throw new LogicException('Result property requires a known input field.');
            }

            $field = $base->fields[$key];

            return new ValueContract(in_array($field->kind, ['date', 'dateOnly'], true) ? 'string' : $field->kind, fields: $field->fields, members: $field->members, nullable: $field->nullable || $field->optional);
        }

        if (in_array($op, ['binary', 'not', 'isset', 'has', 'update', 'delete'], true)) {
            return new ValueContract('bool');
        }

        if ($op === 'timestamp') {
            return new ValueContract('int');
        }

        if ($op === 'object' || $op === 'list') {
            $fields = [];
            $members = [];
            foreach ($node->nodes('items') as $item) {
                $value = $this->expression($item->node('value'), $locals, $input);
                if ($op === 'object') {
                    $fields[$item->string('key')] = $value;
                } else {
                    $members[] = $value;
                }
            }

            return new ValueContract($op === 'object' ? 'object' : 'tuple', fields: $fields, members: $members);
        }

        if ($op === 'call') {
            $scope = [];
            $args = $node->nodes('args');
            foreach ($node->strings('parameters') as $index => $name) {
                $scope[$name] = $this->expression($args[$index], $locals, $input);
            }

            $results = [];
            if (! $this->block($node->nodes('body'), $scope, $results, $input)) {
                throw new LogicException('Shared result must return on every path.');
            }

            return count($results) === 1 ? $results[0] : new ValueContract('union', members: $results);
        }

        throw new LogicException('Cannot infer mutation result expression '.$op);
    }
}
