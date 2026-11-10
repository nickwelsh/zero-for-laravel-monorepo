<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use LogicException;
use Throwable;
use ZeroForLaravel\Generation\ControllerDiscovery;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\FormbraidGenerator;
use ZeroForLaravel\Generation\FrontendDriver;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Generation\Mutations\MutationProgram;
use ZeroForLaravel\Generation\PublicApiGenerator;
use ZeroForLaravel\Generation\ReactFrontendDriver;
use ZeroForLaravel\Generation\TableGenerator;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Tables\TableRegistry;

final class GenerateCommand extends Command
{
    protected $signature = 'zero:generate {--output= : Generated TypeScript path} {--react : Generate a sibling React provider} {--formbraid : Include Formbraid forms regardless of configuration} {--check : Fail if output is missing or out of date}';

    protected $description = 'Generate coordinated Zero schemas, controller queries, page types, and mutations';

    public function handle(ModelRegistry $registry, TypeScriptGenerator $generator, ControllerDiscovery $controllers, ControllerQueryGenerator $queries, Filesystem $files): int
    {
        $path = $this->option('output') ?: config()->string('zero.generation.output');
        if (! is_string($path)) {
            $this->error('Expected a string output.');

            return self::FAILURE;
        }

        // Resolve table relationships before controller fingerprints capture model metadata.
        $tables = resolve(TableRegistry::class)->all();
        $definitions = [...array_values(array_column($controllers->queries(), 'query')), ...$controllers->optionQueries()];
        $mutations = $controllers->mutations();
        $source = $generator->generate($registry);
        $mutationGenerator = resolve(ControllerMutationGenerator::class);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $outputs = [
            $path => $source,
            dirname($path).'/'.$base.'.queries.ts' => $queries->typescript($definitions, './'.$base),
            config()->string('zero.generation.queries') => $queries->php($definitions, $tables),
            dirname($path).'/'.$base.'.mutations.ts' => $mutationGenerator->typescript($mutations),
            config()->string('zero.generation.mutations') => $mutationGenerator->php($mutations),
        ];
        $public = (new PublicApiGenerator)->generate($mutations, $definitions, $base)
            + (new TableGenerator)->typescript($tables, '../'.$base);
        $manifest = dirname($path).'/'.$base.'.files.json';
        $previous = $files->exists($manifest) ? json_decode($files->get($manifest), true, flags: JSON_THROW_ON_ERROR) : [];
        if (! is_array($previous)) {
            throw new LogicException('Invalid generated file manifest.');
        }

        $stale = [];
        foreach ($previous as $relative) {
            if (! is_string($relative) || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
                throw new LogicException('Invalid generated file path.');
            }

            $old = dirname($path).'/'.$relative;
            if (! isset($public[$relative]) && $files->exists($old)) {
                if (! str_starts_with($files->get($old), PublicApiGenerator::HEADER)) {
                    throw new LogicException('Refusing to remove a file not owned by Zero: '.$old);
                }

                $stale[] = $old;
            }
        }

        foreach ($public as $relative => $content) {
            $outputs[dirname($path).'/'.$relative] = $content;
        }

        $outputs[$manifest] = json_encode(array_keys($public), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $formbraid = $this->option('formbraid') || config()->boolean('zero.formbraid.enabled');
        $frontend = $this->option('react') || $formbraid ? resolve(ReactFrontendDriver::class) : resolve(FrontendDriver::class);
        $custom = array_filter($mutations, fn (MutationProgram $mutation): bool => $mutation->optimism === 'custom') !== [];
        foreach ($frontend->files($base, $custom) as $relative => $content) {
            $outputs[dirname($path).'/'.$relative] = $content;
        }

        $configuration = $frontend->configuration();
        $config = dirname($path).'/zero.config.ts';

        if ($formbraid) {
            try {
                foreach (resolve(FormbraidGenerator::class)->generate($path, (bool) $this->option('check')) as $diagnostic) {
                    $this->warn($diagnostic);
                }
            } catch (Throwable $throwable) {
                $this->error($throwable->getMessage());

                return self::FAILURE;
            }

            if (! $this->option('check')) {
                $this->info('Generated Formbraid definitions and Zero controller forms.');
            }
        }

        if ($this->option('check')) {
            if ($stale !== []) {
                $this->error('Stale Zero controller files exist. Run zero:generate.');

                return self::FAILURE;
            }

            foreach ($outputs as $output => $content) {
                if ($files->exists($output) && $files->get($output) === $content) {
                    continue;
                }

                $this->error('Zero contracts are out of date: '.$output.'. Run zero:generate.');

                return self::FAILURE;
            }

            if ($configuration !== null && ! $files->exists($config)) {
                $this->error('Missing zero.config.ts connection options. Run zero:generate.');

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

        $files->delete($stale);

        if ($configuration !== null && ! $files->exists($config)) {
            $files->replace($config, $configuration);
            $this->info("Created {$config}. Configure your public cache URL here; this file will not be overwritten.");
        }

        return self::SUCCESS;
    }
}
