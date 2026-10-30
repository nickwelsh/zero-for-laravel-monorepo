<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation\Mutations;

/**
 * One checked operation tree used by both authoritative and native optimistic execution.
 *
 * @phpstan-type VariableType array{kind:string, type?:string, nullable?:bool, path?:string}
 * @phpstan-type Expression array{op:string, value?:mixed, result?:VariableType, ...}
 * @phpstan-type Contract array{action:string, optimism:string, conceal:bool, returns:bool, parameters:array<string,VariableType>, operations:list<array<string,mixed>>, sites:array<string,string>, version:string, returnType:string}
 */
final readonly class MutationProgram
{
    /**
     * @param  array<string,VariableType>  $parameters
     * @param  list<array<string,mixed>>  $operations
     * @param  array<string,string>  $sites
     * @param  list<string>  $guards
     */
    public function __construct(
        public string $action,
        public string $optimism,
        public bool $conceal,
        public bool $returns,
        public array $parameters,
        public array $operations,
        public array $sites,
        public array $guards,
        public string $fingerprint,
        public string $returnType,
        public ?string $serverResult = null,
        public ?string $clientResult = null,
    ) {}

    public function name(): string
    {
        return 'm_'.substr($this->fingerprint, 0, 32);
    }

    /** @return Contract */
    public function contract(): array
    {
        return ['action' => $this->action, 'optimism' => $this->optimism, 'conceal' => $this->conceal,
            'returns' => $this->returns, 'parameters' => $this->parameters, 'operations' => $this->operations,
            'sites' => $this->sites, 'version' => $this->fingerprint, 'returnType' => $this->returnType];
    }
}
