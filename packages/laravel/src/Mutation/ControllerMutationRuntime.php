<?php

declare(strict_types=1);

namespace ZeroForLaravel\Mutation;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use ZeroForLaravel\Generation\Mutations\OperationNode;
use ZeroForLaravel\Support\Input;

/** Interprets only build-time checked server-owned operations. */
final readonly class ControllerMutationRuntime
{
    /** @param list<Closure(array<string,mixed>):void> $guards */
    public function __construct(private Mutation $mutation, private array $guards = []) {}

    /**
     * @param  list<array<string,mixed>>  $operations
     * @param  array<string,mixed>  $locals
     */
    public function run(array $operations, array $locals): mixed
    {
        return $this->block(array_map(OperationNode::from(...), $operations), $locals)['value'] ?? null;
    }

    /**
     * @param  list<OperationNode>  $operations
     * @param  array<string,mixed>  $locals
     * @return array{value:mixed}|null
     */
    private function block(array $operations, array &$locals): ?array
    {
        foreach ($operations as $op) {
            switch ($op->string('op')) {
                case 'set': $locals[$op->string('name')] = $this->expression($op->node('value'), $locals);
                    break;
                case 'expression': $this->expression($op->node('value'), $locals);
                    break;
                case 'guard': ($this->guards[$op->integer('index')])($locals);
                    break;
                case 'return': return ['value' => $this->expression($op->node('value'), $locals)];
                case 'foreach':
                    $items = $this->expression($op->node('value'), $locals);
                    if (! is_array($items) || ! array_is_list($items) || count($items) > $op->integer('max')) {
                        Input::fail('items', 'Invalid bounded mutation list.');
                    }

                    $keys = [];
                    foreach ($items as $item) {
                        if (! is_array($item) || ! is_string($item['key'] ?? null) || $item['key'] === '' || strlen($item['key']) > 256 || in_array($item['key'], $keys, true)) {
                            Input::fail('items', 'Repeated items require distinct nonempty string keys of at most 256 bytes.');
                        }

                        $keys[] = $item['key'];
                    }

                    foreach ($items as $index => $item) {
                        $locals[$op->string('name')] = $item;
                        $runtime = new self($this->mutation->forItem($keys[$index]), $this->guards);
                        $result = $runtime->block($op->nodes('body'), $locals);
                        if ($result !== null) {
                            return $result;
                        }
                    }

                    break;
                case 'if':
                    $result = $this->block($op->nodes($this->expression($op->node('condition'), $locals) ? 'then' : 'else'), $locals);
                    if ($result !== null) {
                        return $result;
                    }

                    break;
                default: throw new LogicException('Unknown compiled mutation operation.');
            }
        }

        return null;
    }

    /** @param array<string,mixed> $locals */
    private function expression(OperationNode $op, array $locals): mixed
    {
        $e = fn (OperationNode $node): mixed => $this->expression($node, $locals);
        switch ($op->string('op')) {
            case 'literal': return $op->value('value');
            case 'local': return $locals[$op->string('name')];
            case 'get':
                $base = $e($op->node('base'));
                $key = $e($op->node('key'));
                if ((! is_array($base) && ! $base instanceof Model) || (! is_string($key) && ! is_int($key))) {
                    throw new LogicException('Invalid compiled property access.');
                }

                return $base[$key] ?? null;
            case 'validated':
                $request = $e($op->node('base'));
                if (! $request instanceof FormRequest) {
                    throw new LogicException('Expected resolved FormRequest.');
                }

                return $request->validated();
            case 'timestamp': return $this->mutation->timestamp();
            case 'isset': return $e($op->node('value')) !== null;
            case 'has':
                $key = $e($op->node('key'));
                $base = $e($op->node('base'));
                if (! is_array($base) || (! is_string($key) && ! is_int($key))) {
                    throw new LogicException('Invalid compiled array key check.');
                }

                return array_key_exists($key, $base);
            case 'not': return ! $e($op->node('value'));
            case 'list': return array_map(fn (OperationNode $item): mixed => $e($item->node('value')), $op->nodes('items'));
            case 'object':
                $values = [];
                foreach ($op->nodes('items') as $item) {
                    $values[$item->string('key')] = $e($item->node('value'));
                }

                return $values;
            case 'binary': return match ($op->string('operator')) {
                '===' => $e($op->node('left')) === $e($op->node('right')), '!==' => $e($op->node('left')) !== $e($op->node('right')),
                '&&' => $e($op->node('left')) && $e($op->node('right')), '||' => $e($op->node('left')) || $e($op->node('right')),
                default => throw new LogicException('Unknown compiled binary operator.'),
            };
            case 'findUnique': return $this->mutation->findUnique($this->mutation->metadata($op->string('class'))->class, Input::object($e($op->node('unique')), 'unique'));
            case 'insertUnlessExists': return $this->mutation->insertUnlessExists($this->mutation->metadata($op->string('class'))->class, Input::object($e($op->node('unique')), 'unique'), Input::object($e($op->node('data')), 'data'), $op->string('site'));
            case 'upsertUnique': return $this->mutation->upsertUnique($this->mutation->metadata($op->string('class'))->class, Input::object($e($op->node('unique')), 'unique'), Input::object($e($op->node('data')), 'data'), $op->string('site'));
            case 'create': return $this->mutation->create($this->mutation->metadata($op->string('class'))->class, Input::object($e($op->node('data')), 'data'), $op->string('site'));
            case 'reload': return $this->mutation->reloadForUpdate($this->model($e($op->node('record'))));
            case 'update': return $this->mutation->update($this->model($e($op->node('record'))), Input::object($e($op->node('data')), 'data'));
            case 'delete': return $this->mutation->delete($this->model($e($op->node('record'))));
            case 'call':
                $scope = array_combine($op->strings('parameters'), array_map($e, $op->nodes('args')));

                return $this->block($op->nodes('body'), $scope)['value'] ?? null;
            default: throw new LogicException('Unknown compiled mutation expression.');
        }
    }

    private function model(mixed $value): Model
    {
        if (! $value instanceof Model) {
            throw new LogicException('Expected a real mutation model.');
        }

        return $value;
    }
}
