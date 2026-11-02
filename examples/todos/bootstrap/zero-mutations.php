<?php
declare(strict_types=1);
// Generated controller mutation registrations.
return static fn (\ZeroForLaravel\Metadata\ModelRegistry $models, \ZeroForLaravel\Http\ControllerRouteDispatcher $routes): \ZeroForLaravel\Mutation\ControllerMutationRegistry => new \ZeroForLaravel\Mutation\ControllerMutationRegistry($models, $routes, [
'm_8b3ab4da8183badf52abea2ad68fca78' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\ShareListController@__invoke',
  'optimism' => 'disabled',
  'conceal' => true,
  'returns' => false,
  'parameters' => 
  array (
    'request' => 
    array (
      'kind' => 'request',
      'type' => 'App\\Http\\Requests\\ShareListRequest',
    ),
    'list' => 
    array (
      'kind' => 'model',
      'type' => 'App\\Models\\TodoList',
    ),
    'mutation' => 
    array (
      'kind' => 'context',
      'type' => 'ZeroForLaravel\\Mutation\\Mutation',
    ),
  ),
  'operations' => 
  array (
  ),
  'sites' => 
  array (
  ),
  'version' => '8b3ab4da8183badf52abea2ad68fca7815383e12064d179a5c080401912301c7',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_acb842ab9a7e453ee66442d844a5d4ba' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoController@destroy',
  'optimism' => 'generated',
  'conceal' => true,
  'returns' => false,
  'parameters' => 
  array (
    'todo' => 
    array (
      'kind' => 'model',
      'type' => 'App\\Models\\Todo',
    ),
    'mutation' => 
    array (
      'kind' => 'context',
      'type' => 'ZeroForLaravel\\Mutation\\Mutation',
    ),
  ),
  'operations' => 
  array (
    0 => 
    array (
      'op' => 'set',
      'name' => 'todo',
      'value' => 
      array (
        'op' => 'reload',
        'record' => 
        array (
          'op' => 'local',
          'name' => 'todo',
          'result' => 
          array (
            'kind' => 'model',
            'type' => 'App\\Models\\Todo',
          ),
        ),
        'result' => 
        array (
          'kind' => 'model',
          'type' => 'App\\Models\\Todo',
        ),
      ),
    ),
    1 => 
    array (
      'op' => 'guard',
      'index' => 0,
    ),
    2 => 
    array (
      'op' => 'expression',
      'value' => 
      array (
        'op' => 'delete',
        'record' => 
        array (
          'op' => 'local',
          'name' => 'todo',
          'result' => 
          array (
            'kind' => 'model',
            'type' => 'App\\Models\\Todo',
          ),
        ),
        'data' => NULL,
      ),
    ),
  ),
  'sites' => 
  array (
  ),
  'version' => 'acb842ab9a7e453ee66442d844a5d4ba1cbd932cc8b2f2207a0e4c2e45401dbc',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('delete', $todo); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_4682cb01498cda3c3f3982b27e899e24' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoController@store',
  'optimism' => 'generated',
  'conceal' => false,
  'returns' => true,
  'parameters' => 
  array (
    'request' => 
    array (
      'kind' => 'request',
      'type' => 'App\\Http\\Requests\\SaveTodoRequest',
    ),
  ),
  'operations' => 
  array (
    0 => 
    array (
      'op' => 'guard',
      'index' => 0,
    ),
    1 => 
    array (
      'op' => 'set',
      'name' => 'todo',
      'value' => 
      array (
        'op' => 'create',
        'model' => 'Todo',
        'class' => 'App\\Models\\Todo',
        'site' => 's0',
        'data' => 
        array (
          'op' => 'validated',
          'base' => 
          array (
            'op' => 'local',
            'name' => 'request',
            'result' => 
            array (
              'kind' => 'request',
              'type' => 'App\\Http\\Requests\\SaveTodoRequest',
            ),
          ),
          'result' => 
          array (
            'kind' => 'input',
            'type' => 'App\\Http\\Requests\\SaveTodoRequest',
            'path' => '',
          ),
        ),
        'result' => 
        array (
          'kind' => 'model',
          'type' => 'App\\Models\\Todo',
        ),
      ),
    ),
    2 => 
    array (
      'op' => 'return',
      'value' => 
      array (
        'op' => 'object',
        'items' => 
        array (
          0 => 
          array (
            'key' => 'id',
            'value' => 
            array (
              'op' => 'get',
              'base' => 
              array (
                'op' => 'local',
                'name' => 'todo',
                'result' => 
                array (
                  'kind' => 'model',
                  'type' => 'App\\Models\\Todo',
                ),
              ),
              'key' => 
              array (
                'op' => 'literal',
                'value' => 'id',
              ),
              'result' => 
              array (
                'kind' => 'value',
                'type' => 'string',
                'nullable' => false,
              ),
            ),
          ),
        ),
      ),
    ),
  ),
  'sites' => 
  array (
    's0' => 'uuid',
  ),
  'version' => '4682cb01498cda3c3f3982b27e899e240c69be49c28cc16b7ed7950d2c2ef12a',
  'returnType' => 'array',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('create', \App\Models\Todo::class); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_b1d04dfc0e21e6dd42e69dc7ecbd0208' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoController@update',
  'optimism' => 'generated',
  'conceal' => true,
  'returns' => false,
  'parameters' => 
  array (
    'request' => 
    array (
      'kind' => 'request',
      'type' => 'App\\Http\\Requests\\SaveTodoRequest',
    ),
    'todo' => 
    array (
      'kind' => 'model',
      'type' => 'App\\Models\\Todo',
    ),
    'mutation' => 
    array (
      'kind' => 'context',
      'type' => 'ZeroForLaravel\\Mutation\\Mutation',
    ),
  ),
  'operations' => 
  array (
    0 => 
    array (
      'op' => 'set',
      'name' => 'todo',
      'value' => 
      array (
        'op' => 'reload',
        'record' => 
        array (
          'op' => 'local',
          'name' => 'todo',
          'result' => 
          array (
            'kind' => 'model',
            'type' => 'App\\Models\\Todo',
          ),
        ),
        'result' => 
        array (
          'kind' => 'model',
          'type' => 'App\\Models\\Todo',
        ),
      ),
    ),
    1 => 
    array (
      'op' => 'guard',
      'index' => 0,
    ),
    2 => 
    array (
      'op' => 'expression',
      'value' => 
      array (
        'op' => 'update',
        'record' => 
        array (
          'op' => 'local',
          'name' => 'todo',
          'result' => 
          array (
            'kind' => 'model',
            'type' => 'App\\Models\\Todo',
          ),
        ),
        'data' => 
        array (
          'op' => 'validated',
          'base' => 
          array (
            'op' => 'local',
            'name' => 'request',
            'result' => 
            array (
              'kind' => 'request',
              'type' => 'App\\Http\\Requests\\SaveTodoRequest',
            ),
          ),
          'result' => 
          array (
            'kind' => 'input',
            'type' => 'App\\Http\\Requests\\SaveTodoRequest',
            'path' => '',
          ),
        ),
      ),
    ),
  ),
  'sites' => 
  array (
  ),
  'version' => 'b1d04dfc0e21e6dd42e69dc7ecbd020896d878d54ff869a2ade97e009c2b882b',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('update', $todo); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_60e6d901d92ec41b3ff260eb5e534f2b' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoListController@destroy',
  'optimism' => 'generated',
  'conceal' => true,
  'returns' => false,
  'parameters' => 
  array (
    'list' => 
    array (
      'kind' => 'model',
      'type' => 'App\\Models\\TodoList',
    ),
    'mutation' => 
    array (
      'kind' => 'context',
      'type' => 'ZeroForLaravel\\Mutation\\Mutation',
    ),
  ),
  'operations' => 
  array (
    0 => 
    array (
      'op' => 'set',
      'name' => 'list',
      'value' => 
      array (
        'op' => 'reload',
        'record' => 
        array (
          'op' => 'local',
          'name' => 'list',
          'result' => 
          array (
            'kind' => 'model',
            'type' => 'App\\Models\\TodoList',
          ),
        ),
        'result' => 
        array (
          'kind' => 'model',
          'type' => 'App\\Models\\TodoList',
        ),
      ),
    ),
    1 => 
    array (
      'op' => 'guard',
      'index' => 0,
    ),
    2 => 
    array (
      'op' => 'expression',
      'value' => 
      array (
        'op' => 'delete',
        'record' => 
        array (
          'op' => 'local',
          'name' => 'list',
          'result' => 
          array (
            'kind' => 'model',
            'type' => 'App\\Models\\TodoList',
          ),
        ),
        'data' => NULL,
      ),
    ),
  ),
  'sites' => 
  array (
  ),
  'version' => '60e6d901d92ec41b3ff260eb5e534f2baf3b9149a85745b9776e2a42012e3d8b',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('delete', $list); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_7e902a5a03c439d9c9537cfbfefd4847' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoListController@store',
  'optimism' => 'generated',
  'conceal' => false,
  'returns' => true,
  'parameters' => 
  array (
    'request' => 
    array (
      'kind' => 'request',
      'type' => 'App\\Http\\Requests\\SaveTodoListRequest',
    ),
  ),
  'operations' => 
  array (
    0 => 
    array (
      'op' => 'guard',
      'index' => 0,
    ),
    1 => 
    array (
      'op' => 'set',
      'name' => 'list',
      'value' => 
      array (
        'op' => 'create',
        'model' => 'TodoList',
        'class' => 'App\\Models\\TodoList',
        'site' => 's0',
        'data' => 
        array (
          'op' => 'validated',
          'base' => 
          array (
            'op' => 'local',
            'name' => 'request',
            'result' => 
            array (
              'kind' => 'request',
              'type' => 'App\\Http\\Requests\\SaveTodoListRequest',
            ),
          ),
          'result' => 
          array (
            'kind' => 'input',
            'type' => 'App\\Http\\Requests\\SaveTodoListRequest',
            'path' => '',
          ),
        ),
        'result' => 
        array (
          'kind' => 'model',
          'type' => 'App\\Models\\TodoList',
        ),
      ),
    ),
    2 => 
    array (
      'op' => 'return',
      'value' => 
      array (
        'op' => 'object',
        'items' => 
        array (
          0 => 
          array (
            'key' => 'id',
            'value' => 
            array (
              'op' => 'get',
              'base' => 
              array (
                'op' => 'local',
                'name' => 'list',
                'result' => 
                array (
                  'kind' => 'model',
                  'type' => 'App\\Models\\TodoList',
                ),
              ),
              'key' => 
              array (
                'op' => 'literal',
                'value' => 'id',
              ),
              'result' => 
              array (
                'kind' => 'value',
                'type' => 'string',
                'nullable' => false,
              ),
            ),
          ),
        ),
      ),
    ),
  ),
  'sites' => 
  array (
    's0' => 'uuid',
  ),
  'version' => '7e902a5a03c439d9c9537cfbfefd48479296a97e1135f42f7765aa91bfebbf1a',
  'returnType' => 'array',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('create', \App\Models\TodoList::class); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_d1b362479840ebab00de93d623a3673d' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoListController@update',
  'optimism' => 'generated',
  'conceal' => true,
  'returns' => false,
  'parameters' => 
  array (
    'request' => 
    array (
      'kind' => 'request',
      'type' => 'App\\Http\\Requests\\SaveTodoListRequest',
    ),
    'list' => 
    array (
      'kind' => 'model',
      'type' => 'App\\Models\\TodoList',
    ),
    'mutation' => 
    array (
      'kind' => 'context',
      'type' => 'ZeroForLaravel\\Mutation\\Mutation',
    ),
  ),
  'operations' => 
  array (
    0 => 
    array (
      'op' => 'set',
      'name' => 'list',
      'value' => 
      array (
        'op' => 'reload',
        'record' => 
        array (
          'op' => 'local',
          'name' => 'list',
          'result' => 
          array (
            'kind' => 'model',
            'type' => 'App\\Models\\TodoList',
          ),
        ),
        'result' => 
        array (
          'kind' => 'model',
          'type' => 'App\\Models\\TodoList',
        ),
      ),
    ),
    1 => 
    array (
      'op' => 'guard',
      'index' => 0,
    ),
    2 => 
    array (
      'op' => 'expression',
      'value' => 
      array (
        'op' => 'update',
        'record' => 
        array (
          'op' => 'local',
          'name' => 'list',
          'result' => 
          array (
            'kind' => 'model',
            'type' => 'App\\Models\\TodoList',
          ),
        ),
        'data' => 
        array (
          'op' => 'validated',
          'base' => 
          array (
            'op' => 'local',
            'name' => 'request',
            'result' => 
            array (
              'kind' => 'request',
              'type' => 'App\\Http\\Requests\\SaveTodoListRequest',
            ),
          ),
          'result' => 
          array (
            'kind' => 'input',
            'type' => 'App\\Http\\Requests\\SaveTodoListRequest',
            'path' => '',
          ),
        ),
      ),
    ),
  ),
  'sites' => 
  array (
  ),
  'version' => 'd1b362479840ebab00de93d623a3673d34a6e719cbb1332a99f4a693da4b1ea1',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('update', $list); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
]);
