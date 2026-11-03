<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Console\Command;

final class RebuildPermissionsCommand extends Command
{
    protected $signature = 'permissions:rebuild';

    protected $description = 'Install and transactionally rebuild private permission projections';

    public function handle(ProjectionManager $projections): int
    {
        $projections->rebuild();
        $this->info('Permission projections rebuilt. Regenerate Zero contracts after model or schema changes.');

        return self::SUCCESS;
    }
}
