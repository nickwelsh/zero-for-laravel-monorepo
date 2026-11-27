<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Models\TodoList;
use Inertia\Inertia;
use Inertia\Response;
use ZeroForLaravel\Zero;

final class DashboardController extends Controller
{
    public function index(DashboardRequest $input): Response
    {
        $values = $input->validated();
        $lists = TodoList::zeroQuery()->visibleTo($input->user())
            ->with('todos', function ($todos) use ($input, $values): void {
                $todos->visibleTo($input->user())->orderBy('created_at', 'asc')->orderBy('id', 'asc');
                if (isset($values['status']) && $values['status'] !== 'all') {
                    $todos->where('completed', $values['status'] === 'completed');
                }
            });

        return Inertia::render('dashboard', [
            'lists' => Zero::scroll($lists->orderBy('name', 'asc')->paginate(50)),
        ]);
    }
}
