<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use LogicException;
use Throwable;
use ZeroForLaravel\Generation\AutoDetectFrontendDriver;
use ZeroForLaravel\Generation\ControllerDiscovery;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\FormbraidGenerator;
use ZeroForLaravel\Generation\FrontendDriver;
use ZeroForLaravel\Generation\GeneratedLayout;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Generation\Mutations\MutationProgram;
use ZeroForLaravel\Generation\NavigationGenerator;
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
        $layout = new GeneratedLayout($path);
        $outputs = [
            $path => $source,
            $layout->directory.'/'.$layout->sibling('queries').'.ts' => $queries->typescript($definitions, './'.$layout->base).(new NavigationGenerator)->typescript($definitions),
            config()->string('zero.generation.queries') => $queries->php($definitions, $tables),
            $layout->directory.'/'.$layout->sibling('mutations').'.ts' => $mutationGenerator->typescript($mutations),
            config()->string('zero.generation.mutations') => $mutationGenerator->php($mutations),
        ];
        $public = (new PublicApiGenerator)->generate($mutations, $definitions, $layout)
            + (new TableGenerator)->typescript($tables, '../'.$layout->contractPath($layout->base), $definitions);
        $publicRoot = $layout->editableRoot();
        $manifest = $layout->directory.'/'.$layout->sibling('files.json');
        $previous = $files->exists($manifest) ? json_decode($files->get($manifest), true, flags: JSON_THROW_ON_ERROR) : [];
        // The original list tracked files relative to the schema, not the Zero root.
        $previousRoot = $layout->directory;
        if (is_array($previous) && isset($previous['public'])) {
            $previousRoot = $publicRoot;
            $previous = $previous['public'];
        }

        if (! is_array($previous) || ! array_is_list($previous)) {
            throw new LogicException('Invalid generated file manifest.');
        }

        $stale = [];
        foreach ($previous as $relative) {
            if (! is_string($relative) || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
                throw new LogicException('Invalid generated file path.');
            }

            $old = $previousRoot.'/'.$relative;
            if (($previousRoot !== $publicRoot || ! isset($public[$relative])) && $files->exists($old)) {
                if (! str_starts_with($files->get($old), PublicApiGenerator::HEADER)) {
                    throw new LogicException('Refusing to remove a file not owned by Zero: '.$old);
                }

                $stale[] = $old;
            }
        }

        foreach ($public as $relative => $content) {
            $target = $publicRoot.'/'.$relative;
            if (! $this->option('check') && $publicRoot !== $layout->directory && $files->exists($target) && ! str_starts_with($files->get($target), PublicApiGenerator::HEADER)) {
                throw new LogicException('Refusing to overwrite a file not owned by Zero: '.$target);
            }

            $outputs[$target] = $content;
        }

        $paths = array_keys($public);
        $outputs[$manifest] = json_encode($publicRoot === $layout->directory ? $paths : ['public' => $paths], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $formbraid = $this->option('formbraid') || config()->boolean('zero.formbraid.enabled');
        $frontend = $this->option('react') || $formbraid ? resolve(ReactFrontendDriver::class) : resolve(FrontendDriver::class);
        $frontend = $frontend instanceof AutoDetectFrontendDriver ? $frontend->detect() : $frontend;

        $custom = array_filter($mutations, fn (MutationProgram $mutation): bool => $mutation->optimism === 'custom') !== [];
        $modules = $frontend instanceof ReactFrontendDriver ? $frontend->files($layout, $custom) : $frontend?->files($layout->base, $custom) ?? [];
        foreach ($modules as $relative => $content) {
            $outputs[$layout->directory.'/'.$relative] = $content;
        }

        $configuration = $frontend?->configuration();
        $config = $layout->editableRoot().'/zero.config.ts';

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
        $layout->writeIgnoreRules($files);
        $layout->syncModuleIgnores($files, 'public modules', $paths);

        if ($configuration !== null && ! $files->exists($config)) {
            $files->replace($config, $configuration);
            $this->info("Created {$config}. This file will not be overwritten.");
            if ($frontend instanceof ReactFrontendDriver) {
                $this->line('Set VITE_APP_URL="${APP_URL}" and VITE_ZERO_CACHE_URL in .env and .env.example. Keep ZERO_API_KEY server-side.');
            }
        }

        return self::SUCCESS;
    }
}
