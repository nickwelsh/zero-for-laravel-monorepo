<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;
use ZeroForLaravel\Metadata\ModelRegistry;

final class ExposureCommand extends Command
{
    protected $signature = 'zero:exposure {--json : Emit machine-readable model contracts}';

    protected $description = 'Inspect explicitly registered model exposure and visibility rules';

    public function handle(ModelRegistry $registry): int
    {
        $report = [];
        foreach ($registry->all() as $model) {
            $report[$model->name] = $model->toArray() + ['class' => $model->class, 'serverScopes' => count($model->definition->serverScopes)];
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($registry->all() as $model) {
                $this->info($model->name.' → '.$model->table);
                $this->line('  Published: '.implode(', ', array_keys($model->columns)));
                $this->line('  Queries: '.implode(', ', $model->queries).'; mutations: '.implode(', ', $model->mutations));
                $this->line('  Owner rules: '.json_encode($model->authorization, JSON_THROW_ON_ERROR));
                $this->line('  Owner relationships: '.implode(', ', $model->authorizationRelations));
                $this->line('  Server visibility scopes: '.count($model->definition->serverScopes));
                $this->line('  Visibility: '.($model->authorization === [] && $model->authorizationRelations === [] && $model->definition->serverScopes === [] ? 'explicitly public' : 'constrained'));
                $this->line('  Server fills: '.json_encode($model->serverFill, JSON_THROW_ON_ERROR));
                $this->line('  Includes: '.implode(', ', $model->definition->includes));
            }
        }

        if (! $this->option('json')) {
            $this->comment('Publication columns bound cache exposure. Query callbacks enforce per-client visibility.');
        }

        return self::SUCCESS;
    }
}
