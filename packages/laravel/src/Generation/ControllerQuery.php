<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use ZeroForLaravel\Generation\Mutations\ValueContract;

/**
 * A query extracted without invoking its HTTP controller.
 *
 * @phpstan-type OptionReference array{kind: string, path?: string, key?: string}
 * @phpstan-type OptionMetadata array{request: string, field: string, id: string, operation: string, inputs: array<string, OptionReference>, contracts: array<string, array{kind: string, rules: list<string>}>, context: array<string, array{kind: string, type: string}>, authorize: array<string, string>, value: string, label: string}
 */
final readonly class ControllerQuery
{
    /** @param array<string, string> $parameters
     * @param  array<string, string>  $ordinary
     * @param  list<array{parameter: string, class: class-string<Model>, ability: string, fingerprint: string}>  $guards
     * @param  OptionMetadata|null  $optionSource
     */
    public function __construct(
        public string $action,
        public string $component,
        public string $prop,
        public array $parameters,
        public QueryExpression $expression,
        public string $file,
        public int $line,
        public string $schemaFingerprint,
        public array $ordinary = [],
        public array $guards = [],
        public ?array $optionSource = null,
    ) {}

    public function name(): string
    {
        // A single registry key: dots and slashes have native path semantics.
        // Never reuse a registration across changed construction, input, or model contracts.
        return 'q_'.hash('sha256', $this->action."\0".$this->prop."\0".$this->php()."\0".$this->schemaFingerprint."\0".json_encode([$this->parameters, $this->guards], JSON_THROW_ON_ERROR));
    }

    public function php(): string
    {
        return 'static fn (array $args): \\ZeroForLaravel\\Query\\ZeroQuery => '.$this->expression->emit(false);
    }

    /** @return array<string, ValueContract> */
    public function optionContracts(): array
    {
        return array_map(static fn (array $contract): ValueContract => new ValueContract($contract['kind'], rules: $contract['rules']), $this->optionSource['contracts'] ?? []);
    }

    /** @return OptionMetadata */
    public function options(): array
    {
        return $this->optionSource ?? throw new LogicException('Expected an option query.');
    }

    public function typescript(): string
    {
        return '({args, ctx}) => '.$this->expression->emit(true);
    }
}
