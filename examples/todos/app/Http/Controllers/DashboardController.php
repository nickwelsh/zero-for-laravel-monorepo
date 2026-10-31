<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Models\TodoList;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function index(DashboardRequest $input): Response
    {
        $values = $input->validated();
        $lists = TodoList::zeroQuery()
            ->related('todos', fn ($todos) => $todos->orderBy('created_at', 'asc')->orderBy('id', 'asc'));
        if (isset($values['status']) && $values['status'] !== 'all') {
            $lists = TodoList::zeroQuery()->related('todos', fn ($todos) => $todos
                ->where('completed', $values['status'] === 'completed')
                ->orderBy('created_at', 'asc')->orderBy('id', 'asc'));
        }

        return Inertia::render('dashboard', [
            'lists' => $lists->orderBy('name', 'asc')->infinite(50),
        ]);
    }
}
