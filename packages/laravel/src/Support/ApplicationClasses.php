<?php

declare(strict_types=1);

namespace ZeroForLaravel\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Finder\Glob;

/** Find application classes in the production PSR-4 mapping in composer.json. */
class ApplicationClasses
{
    /** @return list<class-string> */
    public function all(): array
    {
        $discovery = config('zero.discovery', 'auto');
        if ($discovery !== 'auto' && (! is_array($discovery) || ! array_is_list($discovery))) {
            throw new LogicException('zero.discovery must be auto or a list of classes and globs.');
        }

        $classes = [];
        $namespaces = [];
        $pathPatterns = [];
        if (is_array($discovery)) {
            foreach ($discovery as $entry) {
                if (! is_string($entry) || $entry === '') {
                    throw new LogicException('zero.discovery entries must be class names or globs.');
                }

                if (str_contains($entry, '/')) {
                    $pathPatterns[] = Glob::toRegex(str_starts_with($entry, '/') ? $entry : base_path($entry));
                } elseif (str_contains($entry, '*')) {
                    $namespaces[] = $entry;
                } elseif (class_exists($entry)) {
                    $classes[] = $entry;
                } else {
                    throw new LogicException('Unknown Zero discovery class: '.$entry.'.');
                }
            }

            if ($namespaces === [] && $pathPatterns === []) {
                sort($classes);

                return array_values(array_unique($classes));
            }
        }

        $files = new Filesystem;
        /** @var array{autoload?: array{'psr-4'?: array<string, string|list<string>>}} $composer */
        $composer = $files->json(base_path('composer.json'));
        foreach ($composer['autoload']['psr-4'] ?? [] as $namespace => $paths) {
            foreach ((array) $paths as $path) {
                $directory = base_path($path);
                if (! $files->isDirectory($directory)) {
                    continue;
                }

                foreach ($files->allFiles($directory) as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }

                    $class = rtrim($namespace, '\\').'\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
                    if (is_array($discovery) && ! Str::is($namespaces, $class)) {
                        $matched = false;
                        foreach ($pathPatterns as $pattern) {
                            if (preg_match($pattern, $file->getPathname()) === 1) {
                                $matched = true;
                                break;
                            }
                        }

                        if (! $matched) {
                            continue;
                        }
                    }

                    if (class_exists($class)) {
                        $classes[] = $class;
                    }
                }
            }
        }

        sort($classes);

        return array_values(array_unique($classes));
    }
}
