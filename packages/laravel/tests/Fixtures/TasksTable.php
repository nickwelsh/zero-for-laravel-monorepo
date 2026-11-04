<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use ZeroForLaravel\Tables\AllowedFilter;
use ZeroForLaravel\Tables\AllowedGroup;
use ZeroForLaravel\Tables\Table;
use ZeroForLaravel\Tables\TableContext;

final class TasksTable
{
    public function definition(TableContext $context): Table
    {
        return Table::for(Task::class)->visibleTo($context->user())
            ->allowedFilters([AllowedFilter::partial('title'), AllowedFilter::exact('completed', 'done')])
            ->allowedSorts(['title'])->defaultSort('title')
            ->allowedGroups([AllowedGroup::field('done'), AllowedGroup::field('title'), AllowedGroup::belongsTo('list')->label('name')])
            ->defaultGroupBy(['done', 'list'])->maxGroupDepth(2)->pageSize(2);
    }
}
