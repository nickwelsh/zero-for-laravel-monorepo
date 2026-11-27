<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use ZeroForLaravel\Tables\AllowedFilter;
use ZeroForLaravel\Tables\AllowedRelation;
use ZeroForLaravel\Tables\Table;
use ZeroForLaravel\Tables\TableContext;

final class TaskReportTable
{
    public function definition(TableContext $context): Table
    {
        return Table::for(Task::class)->visibleTo($context->user())
            ->allowedRelations([
                AllowedRelation::named('tags')->choices('id', 'name')->lookup('name', 3),
                AllowedRelation::named('list')->allowedFilters([AllowedFilter::text('name')]),
            ])->displayColumns(['title' => 'Task title', 'tags' => 'Assigned tags']);
    }
}
