<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use ZeroForLaravel\Generation\ControllerDiscovery;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;

final class GenerateCommand extends Command
{
    protected $signature = 'zero:generate {--output= : Generated TypeScript path} {--react : Generate a sibling React provider} {--check : Fail if output is missing or out of date}';

    protected $description = 'Generate coordinated Zero schemas, controller queries, page types, and mutations';

    public function handle(ModelRegistry $registry, TypeScriptGenerator $generator, ControllerDiscovery $controllers, ControllerQueryGenerator $queries, Filesystem $files): int
    {
        $path = $this->option('output') ?: config()->string('zero.output');
        if (! is_string($path)) {
            $this->error('Expected a string output.');

            return self::FAILURE;
        }

        $source = $generator->generate($registry);
        $definitions = array_values(array_column($controllers->queries(), 'query'));
        $mutations = $controllers->mutations();
        $mutationGenerator = resolve(\ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator::class);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $outputs = [
            $path => $source,
            dirname($path).'/'.$base.'.queries.ts' => $queries->typescript($definitions, './'.$base),
            config()->string('zero.controller_output') => $queries->php($definitions),
            dirname($path).'/'.$base.'.mutations.ts' => $mutationGenerator->typescript($mutations),
            config()->string('zero.mutation_output') => $mutationGenerator->php($mutations),
        ];
        $react = $this->option('react') || config()->boolean('zero.react');
        $config = dirname($path).'/zero.config.ts';
        if ($react) {
            $custom = array_filter($mutations, fn (\ZeroForLaravel\Generation\Mutations\MutationProgram $mutation): bool => $mutation->optimism === 'custom') !== [];
            $outputs[dirname($path).'/'.$base.'.react.ts'] = $generator->generateReact('./'.$base, './zero.config', './'.$base.'.mutations', $custom);
        }

        foreach ($registry->all() as $model) {
            foreach ($model->diagnostics as $diagnostic) {
                $this->warn($diagnostic);
            }
        }

        if ($this->option('check')) {
            foreach ($outputs as $output => $content) {
                if ($files->exists($output) && $files->get($output) === $content) {
                    continue;
                }

                $this->error('Zero contracts are out of date. Run zero:generate.');

                return self::FAILURE;
            }

            if ($react && ! $files->exists($config)) {
                $this->error('Missing zero.config.ts connection options. Run zero:generate --react.');

                return self::FAILURE;
            }

            $this->info('Zero contracts are current.');

            return self::SUCCESS;
        }

        foreach ($outputs as $output => $content) {
            $files->ensureDirectoryExists(dirname($output));
            $files->replace($output, $content);
            $this->info("Generated {$output}.");
        }

        if ($react && ! $files->exists($config)) {
            $files->replace($config, "import type {ProviderOptions} from '@nickwelsh/zero-for-laravel/react';\n\n// Public connection options only; never put the cache API key here.\nexport const options = {cacheURL: 'http://localhost:4848'} satisfies ProviderOptions;\n");
            $this->info("Created {$config}. Configure your public cache URL here; this file will not be overwritten.");
        }

        return self::SUCCESS;
    }
}
