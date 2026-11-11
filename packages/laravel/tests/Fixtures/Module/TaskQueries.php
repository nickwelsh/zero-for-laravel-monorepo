<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures\Module;

use Illuminate\Http\Request;
use LogicException;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Tests\Fixtures\DateQueryRequest;
use ZeroForLaravel\Tests\Fixtures\QueryRequest;
use ZeroForLaravel\Tests\Fixtures\Task;

final class TaskQueries
{
    public function __construct()
    {
        throw new LogicException('Query classes must never be instantiated to discover or execute declarations.');
    }

    public static function helper(): ZeroQuery
    {
        return Task::zeroQuery();
    }

    public function search(QueryRequest $input): ZeroQuery
    {
        $validated = $input->validated();
        $query = Task::zeroQuery()->visibleTo($input->user());
        if (isset($validated['title']) && $validated['title'] !== '') {
            $query->where('title', 'LIKE', '%'.$validated['title'].'%');
        }

        return $query->orderBy('title', 'desc')->orderBy('id', 'asc')->limit(20);
    }

    public function dated(DateQueryRequest $input): ZeroQuery
    {
        $validated = $input->validated();

        return Task::zeroQuery()->visibleTo($input->user())->where('completed_at', '>=', $validated['after']);
    }

    public function window(Request $request): ZeroQuery
    {
        return Task::zeroQuery()->visibleTo($request->user())->orderBy('title', 'desc')->infinite(2);
    }

    public function pages(Request $request): ZeroQuery
    {
        return Task::zeroQuery()->visibleTo($request->user())->orderBy('title', 'desc')->paginate(2);
    }

    public function detail(Request $request, string $id): ZeroQuery
    {
        return Task::zeroQuery()->visibleTo($request->user())->where('id', $id)->one();
    }
}
