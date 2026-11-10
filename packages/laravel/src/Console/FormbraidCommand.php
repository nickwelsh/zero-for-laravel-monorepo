<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;

final class FormbraidCommand extends Command
{
    protected $signature = 'zero:formbraid {--output= : Generated TypeScript path} {--check : Fail if output is missing or out of date}';

    protected $description = 'Generate callback-backed Formbraid descriptors for Zero controller mutations';

    public function handle(): int
    {
        return $this->call('zero:generate', [
            '--formbraid' => true,
            '--output' => $this->option('output'),
            '--check' => $this->option('check'),
        ]);
    }
}
