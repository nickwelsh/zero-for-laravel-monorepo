<?php

declare(strict_types=1);

namespace ZeroForLaravel\Development;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Illuminate\Filesystem\Filesystem;
use LogicException;

/** Stage both environment files before writing either one. Never echo their contents. */
final class SetupEnvironmentFile
{
    private string $content;

    public function __construct(private readonly Filesystem $files, private readonly string $path)
    {
        if (is_link($path)) {
            throw new LogicException('Setup will not edit a linked '.basename($path).'.');
        }

        $this->content = $files->exists($path) ? $files->get($path) : '';
    }

    /** @param array<string, string> $settings Raw environment file literals, not credentials copied from another file. */
    public function stage(array $settings, bool $private): void
    {
        $newline = str_contains($this->content, "\r\n") ? "\r\n" : "\n";
        try {
            $values = Dotenv::parse($this->content);
            $key = $values['ZERO_API_KEY'] ?? '';
            if (! $private || $key === '') {
                $settings['ZERO_API_KEY'] = $private ? bin2hex(random_bytes(32)) : '';
            }

            foreach ($settings as $name => $literal) {
                if (str_ends_with($name, '_ALLOWED_CLIENT_HEADERS') && filled($values[$name] ?? null)) {
                    $headers = array_map(trim(...), explode(',', (string) $values[$name]));
                    if (in_array($literal, array_map(strtolower(...), $headers), true)) {
                        continue;
                    }

                    $literal = implode(',', [...$headers, $literal]);
                }

                $pattern = '/^[\t ]*(?:export[\t ]+)?'.preg_quote($name, '/').'[\t ]*=[^\r\n]*/m';
                $count = preg_match_all($pattern, $this->content, $matches);
                if ($count === 0 && ! array_key_exists($name, $values)) {
                    $this->content .= (str_ends_with($this->content, "\n") || $this->content === '' ? '' : $newline).$name.'='.$literal.$newline;
                } elseif ($count === 1 && array_key_exists($name, $values)) {
                    // Refuse multiline values rather than replacing just their first line.
                    Dotenv::parse($matches[0][0]);
                    $this->content = (string) preg_replace_callback($pattern, fn (): string => $name.'='.$literal, $this->content);
                } else {
                    throw new LogicException('Resolve the ambiguous '.$name.' entry in '.basename($this->path).' before setup.');
                }
            }

            $parsed = Dotenv::parse($this->content);
            if ($private !== filled($parsed['ZERO_API_KEY'] ?? null)) {
                throw new LogicException('Cannot safely configure ZERO_API_KEY in '.basename($this->path).'.');
            }
        } catch (InvalidFileException) {
            throw new LogicException('Cannot safely edit '.basename($this->path).'; check its dotenv syntax and multiline entries.');
        }
    }

    public function save(bool $private): void
    {
        $this->files->replace($this->path, $this->content, $private ? 0600 : null);
    }
}
