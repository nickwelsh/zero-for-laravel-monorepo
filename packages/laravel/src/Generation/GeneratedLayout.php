<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Filesystem\Filesystem;

/** Shared contracts live in generated/; public modules follow the PHP namespace of each class. */
final readonly class GeneratedLayout
{
    public string $directory;

    public string $base;

    private bool $nested;

    public function __construct(string $output)
    {
        $this->directory = dirname($output);
        $this->base = pathinfo($output, PATHINFO_FILENAME);
        $this->nested = basename($this->directory) === 'generated' && basename($output) === 'index.ts';
    }

    public function editableRoot(): string
    {
        return $this->nested ? dirname($this->directory) : $this->directory;
    }

    public function queryImport(): string
    {
        return './'.$this->contractPath($this->sibling('queries'));
    }

    public function contractPath(string $module): string
    {
        return ($this->nested ? 'generated/' : '').$module;
    }

    public function sibling(string $name): string
    {
        return $this->nested ? $name : $this->base.'.'.$name;
    }

    public function configImport(): string
    {
        return $this->nested ? '../zero.config' : './zero.config';
    }

    public function formbraidDirectory(): string
    {
        return $this->nested ? $this->editableRoot().'/formbraid' : 'resources/js/formbraid/zero';
    }

    /** @param list<string> $modules Paths relative to the Zero root. */
    public function syncModuleIgnores(Filesystem $files, string $group, array $modules): void
    {
        if (! $this->nested) {
            return;
        }

        $path = $this->editableRoot().'/.gitignore';
        $existing = $files->exists($path) ? $files->get($path) : '';
        $newline = str_contains($existing, "\r\n") ? "\r\n" : "\n";
        $start = '# Zero: '.$group;
        $end = '# End Zero: '.$group;
        sort($modules);
        $block = $modules === [] ? '' : $start.$newline.'/'.implode($newline.'/', $modules).$newline.$end.$newline;
        $pattern = '/^'.preg_quote($start, '/').'\r?\n.*?^'.preg_quote($end, '/').'(?:\r?\n|$)/ms';
        $updated = preg_match($pattern, $existing)
            ? preg_replace_callback($pattern, static fn (): string => $block, $existing)
            : $existing.($existing !== '' && ! str_ends_with($existing, "\n") ? $newline : '').$block;
        if ($updated !== null && $existing !== $updated) {
            $files->replace($path, $updated);
        }
    }

    public function writeIgnoreRules(Filesystem $files): void
    {
        if ($this->nested) {
            $this->appendIgnoreRules($files, $this->editableRoot().'/.gitignore', ['generated/']);
        }

        $rules = [];
        foreach (['queries', 'mutations'] as $kind) {
            $path = 'bootstrap/zero-'.$kind.'.php';
            if (config('zero.generation.'.$kind) === base_path($path)) {
                $rules[] = '/'.$path;
            }
        }

        $this->appendIgnoreRules($files, base_path('.gitignore'), $rules);
    }

    /** @param list<string> $rules */
    private function appendIgnoreRules(Filesystem $files, string $path, array $rules): void
    {
        if ($rules === []) {
            return;
        }

        $existing = $files->exists($path) ? $files->get($path) : '';
        $missing = array_diff($rules, explode("\n", str_replace("\r\n", "\n", $existing)));
        if ($missing !== []) {
            $newline = str_contains($existing, "\r\n") ? "\r\n" : "\n";
            $files->ensureDirectoryExists(dirname($path));
            $files->append($path, ($existing !== '' && ! str_ends_with($existing, "\n") ? $newline : '').implode($newline, $missing).$newline);
        }
    }
}
