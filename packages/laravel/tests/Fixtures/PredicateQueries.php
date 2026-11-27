<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Http\Request;
use ZeroForLaravel\Query\ZeroQuery;

final class PredicateQueries
{
    public function constrained(Request $request): ZeroQuery
    {
        return PredicateTask::zeroQuery()->whereHas('comments', fn ($q) => $q->visibleTo($request->user())->where('id', 'a')->orWhere('id', 'b'))->get();
    }

    public function filtered(Request $request, bool $flag): ZeroQuery
    {
        return Task::zeroQuery()->visibleTo($request->user())
            ->where([['title', 'not like', 'secret%'], ['done', '=', false]])
            ->whereIn('completed_at', ['2026-01-01', null])
            ->when($flag, fn ($q) => $q->whereNotBetween('title', ['b', 'h']), fn ($q) => $q->orWhereNotIn('list_id', ['blocked']))
            ->unless($flag, fn ($q, bool $value) => $q->where(['done' => $value]))
            ->orWhereNotNull('list_id')
            ->withWhereHas('comments', fn ($q) => $q->visibleTo($request->user())->whereKey(['a', 'b']), '>', 0)
            ->latest()->oldest('title')->orderByDesc('id')->take(4)->get();
    }
}
