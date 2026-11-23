<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Filesystem\Filesystem;

/** The generated/index.ts convention keeps application-owned files outside the generated tree. */
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
        return ($this->nested ? './generated/' : './').$this->sibling('queries');
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
        return $this->nested ? $this->directory.'/formbraid' : 'resources/js/formbraid/zero';
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
