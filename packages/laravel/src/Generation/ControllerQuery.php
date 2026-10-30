<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

/** A query extracted without invoking its HTTP controller. */
final readonly class ControllerQuery
{
    /** @param array<string, string> $parameters
     * @param  array<string, string>  $ordinary
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
    ) {}

    public function name(): string
    {
        // A single registry key: dots and slashes have native path semantics.
        // Never reuse a registration across changed construction, input, or model contracts.
        return 'q_'.hash('sha256', $this->action."\0".$this->prop."\0".$this->php()."\0".$this->schemaFingerprint."\0".json_encode($this->parameters, JSON_THROW_ON_ERROR));
    }

    public function php(): string
    {
        return 'static fn (array $args): \\ZeroForLaravel\\Query\\Query => '.$this->expression->emit(false);
    }

    public function typescript(): string
    {
        return '({args}) => '.$this->expression->emit(true);
    }
}
