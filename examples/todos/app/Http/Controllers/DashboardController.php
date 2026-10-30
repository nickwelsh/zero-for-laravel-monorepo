<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TodoList;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('dashboard', [
            'lists' => TodoList::zeroQuery()
                ->related('todos', fn ($todos) => $todos->orderBy('created_at', 'asc')->orderBy('id', 'asc'))
                ->orderBy('name', 'asc')->infinite(50),
        ]);
    }
}
