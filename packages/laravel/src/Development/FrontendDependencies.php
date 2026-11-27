<?php

declare(strict_types=1);

namespace ZeroForLaravel\Development;

use Illuminate\Filesystem\Filesystem;

final class FrontendDependencies
{
    /** @return list<string>|null Empty when installed; null when no supported manager can be inferred. */
    public static function installCommand(Filesystem $files): ?array
    {
        $path = base_path('package.json');
        /** @var array{packageManager?: string, dependencies?: array<string, string>, devDependencies?: array<string, string>} $package */
        $package = $files->isFile($path) ? $files->json($path) : [];
        $declared = ($package['dependencies'] ?? []) + ($package['devDependencies'] ?? []);
        $add = [];
        $install = false;
        foreach (['@nickwelsh/zero-for-laravel' => '', '@rocicorp/zero' => '@1.11.4', 'zod' => ''] as $name => $suffix) {
            $installed = base_path('node_modules/'.$name.'/package.json');
            if (! isset($declared[$name]) || ($name === '@rocicorp/zero' && $declared[$name] !== '1.11.4')) {
                $add[] = $name.$suffix;
            } elseif (! $files->isFile($installed) || ($name === '@rocicorp/zero' && ($files->json($installed)['version'] ?? null) !== '1.11.4')) {
                $install = true;
            }
        }

        if ($add === [] && ! $install) {
            return [];
        }

        $locks = ['npm' => ['package-lock.json', 'npm-shrinkwrap.json'], 'pnpm' => ['pnpm-lock.yaml'], 'yarn' => ['yarn.lock'], 'bun' => ['bun.lock', 'bun.lockb']];
        if (isset($package['packageManager'])) {
            $manager = explode('@', $package['packageManager'], 2)[0];
            if (! isset($locks[$manager])) {
                return null;
            }
        } else {
            $matches = [];
            foreach ($locks as $name => $lockfiles) {
                foreach ($lockfiles as $lockfile) {
                    if ($files->isFile(base_path($lockfile))) {
                        $matches[] = $name;
                        break;
                    }
                }
            }

            if (count($matches) > 1) {
                return null;
            }

            $manager = $matches[0] ?? 'npm';
        }

        if ($add === []) {
            return [$manager, 'install'];
        }

        return [$manager, $manager === 'npm' ? 'install' : 'add', in_array($manager, ['npm', 'pnpm'], true) ? '--save-exact' : '--exact', ...$add];
    }
}
