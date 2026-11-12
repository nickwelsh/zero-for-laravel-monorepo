<?php

namespace App\Http\Controllers;

use App\Tables\TodosTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use ZeroForLaravel\Tables\TableContext;
use ZeroForLaravel\Tables\TableURLState;

class URLStateController extends Controller
{
    public function __invoke(Request $request, TodosTable $definition): Response
    {
        $table = $definition->definition(new TableContext($request));
        // These are user-editable view defaults, not authorization restrictions.
        $defaults = [
            'active' => ['groupBy' => [], 'filter' => ['kind' => 'condition', 'field' => 'completed', 'operator' => 'equals', 'value' => false]],
            'completed' => ['groupBy' => [], 'filter' => ['kind' => 'condition', 'field' => 'completed', 'operator' => 'equals', 'value' => true]],
        ];
        $initialInputs = [];
        foreach ($defaults as $key => $state) {
            $defaults[$key] = TableURLState::normalize($table, $state);
            $initialInputs[$key] = TableURLState::read($table, $request->server('QUERY_STRING', ''), 'table.'.$key, $state);
        }

        return Inertia::render('url-state', ['initialInputs' => $initialInputs, 'initialURL' => $request->getRequestUri(), 'defaults' => $defaults]);
    }
}
