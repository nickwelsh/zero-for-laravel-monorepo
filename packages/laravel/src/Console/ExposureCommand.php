<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Scopes\ScopeRegistry;

final class ExposureCommand extends Command
{
    protected $signature = 'zero:exposure {--json : Emit machine-readable model contracts}';

    protected $description = 'Inspect explicitly registered model exposure and visibility rules';

    public function handle(ModelRegistry $registry): int
    {
        $report = [];
        foreach ($registry->all() as $model) {
            $report[$model->name] = $model->toArray() + ['class' => $model->class, 'policyVisibility' => $model->policyVisibility, 'globalScopes' => array_keys(ScopeRegistry::globals($model))];
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($registry->all() as $model) {
                $this->info($model->name.' → '.$model->table);
                $this->line('  Published: '.implode(', ', array_keys($model->columns)));
                $this->line('  Visibility: '.($model->policyVisibility ? 'Laravel policy via visibleTo / captured Gate' : 'explicitly public'));
                $this->line('  Global Zero scopes: '.implode(', ', array_keys(ScopeRegistry::globals($model))));
                $this->line('  Creation defaults: '.json_encode($model->toArray()['creationDefaults'], JSON_THROW_ON_ERROR));
                $this->line('  Relationships: '.implode(', ', array_keys($model->relations)));
            }
        }

        if (! $this->option('json')) {
            $this->comment('Publication columns bound cache exposure. Query callbacks enforce per-client visibility.');
        }

        return self::SUCCESS;
    }
}
