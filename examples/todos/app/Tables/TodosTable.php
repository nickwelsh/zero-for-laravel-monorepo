<?php

declare(strict_types=1);

namespace App\Tables;

use App\Models\Todo;
use ZeroForLaravel\Tables\AllowedFilter;
use ZeroForLaravel\Tables\AllowedGroup;
use ZeroForLaravel\Tables\Table;
use ZeroForLaravel\Tables\TableContext;

final class TodosTable
{
    public function definition(TableContext $context): Table
    {
        return Table::for(Todo::class)->visibleTo($context->user())
            ->allowedFilters([
                AllowedFilter::exact('completed'),
                AllowedFilter::exact('list', 'list_id'),
                AllowedFilter::text('title')->operators(['equals', 'notEquals', 'contains', 'startsWith', 'endsWith']),
            ])
            ->allowedSorts(['created_at', 'title'])->defaultSort('created_at')
            ->allowedGroups([AllowedGroup::field('completed'), AllowedGroup::belongsTo('list')->label('name')])
            ->defaultGroupBy(['completed', 'list'])->maxGroupDepth(2)->pageSize(50);
    }
}
