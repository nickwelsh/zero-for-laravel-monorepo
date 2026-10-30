<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation\Mutations;

use LogicException;

final readonly class ControllerMutationGenerator
{
    public function __construct(private MutationSchemaDriver $schemas = new ZodMutationSchemaDriver) {}

    /** @param list<MutationProgram> $programs */
    public function php(array $programs): string
    {
        $lines = ['<?php', 'declare(strict_types=1);', '// Generated controller mutation registrations.',
            'return static fn (\\ZeroForLaravel\\Metadata\\ModelRegistry $models, \\ZeroForLaravel\\Http\\ControllerRouteDispatcher $routes): \\ZeroForLaravel\\Mutation\\ControllerMutationRegistry => new \\ZeroForLaravel\\Mutation\\ControllerMutationRegistry($models, $routes, ['];
        foreach ($programs as $program) {
            $result = (new MutationContracts)->server($program);
            $lines[] = var_export($program->name(), true)." => ['contract' => ".var_export($program->contract(), true).", 'guards' => [".implode(',', $program->guards).'], \'result\' => static fn (mixed $value): mixed => ('.$result->php().')->normalize($value)],';
        }

        return implode("\n", [...$lines, ']);', '']);
    }

    /** @param list<MutationProgram> $programs */
    public function typescript(array $programs): string
    {
        $contracts = [];
        foreach ($programs as $program) {
            $contract = $program->contract();
            $contract['sites'] = (object) $contract['sites'];
            $contract['parameters'] = (object) $contract['parameters'];
            $contracts[$program->name()] = $contract;
        }

        $hasCustom = array_filter($programs, fn (MutationProgram $program): bool => $program->optimism === 'custom') !== [];
        $lines = ['// Generated controller mutations; deploy with the matching PHP manifest.',
            '/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */',
            "import type {Schema} from '@rocicorp/zero';",
            'import {createControllerMutations, type ModelContract'.($hasCustom ? ', type ControllerMutationOptions' : '')."} from '@nickwelsh/zero-for-laravel';",
            $this->schemas->imports(),
            'export const controllerMutationContracts = '.json_encode((object) $contracts, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).' as const;',
        ];
        $inference = new MutationContracts;
        $custom = [];
        foreach ($programs as $index => $program) {
            try {
                $input = $inference->input($program->parameters);
                $server = $inference->server($program);
            } catch (LogicException $error) {
                throw new LogicException($program->action.': '.$error->getMessage(), previous: $error);
            }

            $fallback = count(array_filter($program->parameters, fn (array $parameter): bool => $parameter['kind'] === 'model')) > 0;
            $client = $program->optimism !== 'generated' ? new ValueContract('void') : ($fallback && $server->kind !== 'void' ? new ValueContract('union', members: [$server, new ValueContract('void')]) : $server);
            if ($program->clientResult !== null) {
                $client = ValueContract::parse($program->clientResult);
            }

            $lines[] = 'export type Input'.$index.' = '.$input->typescript().';';
            $lines[] = 'export type ServerResult'.$index.' = '.$server->typescript().';';
            $lines[] = 'export type ClientResult'.$index.' = '.$client->typescript().';';
            $lines[] = 'export const inputSchema'.$index.' = '.$this->schemas->value($input).';';
            $lines[] = 'export const serverResultSchema'.$index.' = '.$this->schemas->value($server).';';
            $lines[] = 'export const clientResultSchema'.$index.' = '.$this->schemas->value($client).';';
            $lines[] = 'const codec'.$index.' = {input: inputSchema'.$index.', encode: (input: Input'.$index.') => '.$input->encode('input').', serverResult: serverResultSchema'.$index.', clientResult: clientResultSchema'.$index.'};';
            if ($program->optimism === 'custom') {
                $custom[] = json_encode($program->action, JSON_THROW_ON_ERROR).': NonNullable<ControllerMutationOptions<Input'.$index.', ServerResult'.$index.', ClientResult'.$index.'>["optimistic"]>';
            }
        }

        $lines[] = 'export function createControllerMutationBindings<S extends Schema>(schema: S, models: Readonly<Record<string, ModelContract>>'.($custom === [] ? '' : ', projections: {'.implode('; ', $custom).'}').') {';
        $lines[] = '  const codecs = {';
        foreach ($programs as $index => $program) {
            $lines[] = '    '.json_encode($program->name(), JSON_THROW_ON_ERROR).': {...codec'.$index.($program->optimism === 'custom' ? ', optimistic: projections['.json_encode($program->action, JSON_THROW_ON_ERROR).']' : '').'},';
        }

        $lines[] = '  };';
        $lines[] = '  const runtime = createControllerMutations(schema, models, controllerMutationContracts, codecs);';
        $lines[] = '  return {...runtime, actions: {';
        foreach ($programs as $program) {
            $name = json_encode($program->name(), JSON_THROW_ON_ERROR);
            $lines[] = '    '.json_encode($program->action, JSON_THROW_ON_ERROR).': runtime.bind('.$name.', codecs['.$name.']),';
        }

        $lines[] = '  }};';
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }
}
