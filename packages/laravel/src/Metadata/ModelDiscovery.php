<?php

declare(strict_types=1);

namespace ZeroForLaravel\Metadata;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use ZeroForLaravel\Attributes;

final class ModelDiscovery
{
    /** @return list<class-string<Model>> */
    public function registered(): array
    {
        $models = config()->array('zero.models');
        if (! array_is_list($models)) {
            throw new LogicException('Zero models must be a list of Eloquent model classes.');
        }

        $classes = [];
        foreach ($models as $model) {
            if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
                throw new LogicException('Zero models must be a list of Eloquent model classes.');
            }

            $classes[] = $model;
        }

        if ($classes === []) {
            $roots = config()->array('zero.discovery');
            if ($roots === []) {
                $roots = [['path' => app_path('Models'), 'namespace' => app()->getNamespace().'Models']];
            }

            foreach ($roots as $root) {
                if (! is_array($root) || ! is_string($root['path'] ?? null) || ! is_string($root['namespace'] ?? null)) {
                    throw new LogicException('Zero discovery roots require a path and namespace.');
                }

                $classes = [...$classes, ...$this->discover($root['path'], $root['namespace'])];
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @return list<class-string<Model>>
     */
    public function discover(string $directory, string $namespace): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $classes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1, -4);
            $class = rtrim($namespace, '\\').'\\'.str_replace('/', '\\', $relative);
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            if (method_exists($class, 'zero')) {
                $classes[] = $class;

                continue;
            }

            for ($reflection = new ReflectionClass($class); $reflection; $reflection = $reflection->getParentClass()) {
                foreach ([Attributes\ZeroQuery::class, Attributes\ZeroMutate::class, Attributes\ZeroAuthorize::class, Attributes\ZeroAuthorizeRelation::class, Attributes\ZeroPublic::class] as $attribute) {
                    if ($reflection->getAttributes($attribute) !== []) {
                        $classes[] = $class;

                        continue 3;
                    }
                }
            }
        }

        sort($classes);

        return $classes;
    }
}
