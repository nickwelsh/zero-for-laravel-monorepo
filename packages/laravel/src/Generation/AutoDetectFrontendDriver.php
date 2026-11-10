<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Filesystem\Filesystem;
use LogicException;

final readonly class AutoDetectFrontendDriver implements FrontendDriver
{
    public function files(string $base, bool $custom): array
    {
        return $this->detect()?->files($base, $custom) ?? [];
    }

    public function configuration(): ?string
    {
        return $this->detect()?->configuration();
    }

    private function detect(): ?FrontendDriver
    {
        $files = new Filesystem;
        if (! $files->exists(base_path('package.json'))) {
            return null;
        }

        /** @var array{dependencies?: array<string, string>, devDependencies?: array<string, string>} $package */
        $package = $files->json(base_path('package.json'));
        $dependencies = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);
        $frameworks = array_intersect(['react', 'vue', 'svelte'], array_keys($dependencies));
        if ($frameworks === []) {
            return null;
        }

        if (count($frameworks) !== 1 || ! in_array('react', $frameworks, true)) {
            throw new LogicException('Cannot automatically select a Zero frontend driver for '.implode(', ', $frameworks).'. Only React is currently supported; set zero.frontend.driver to a custom FrontendDriver class.');
        }

        return resolve(ReactFrontendDriver::class);
    }
}
