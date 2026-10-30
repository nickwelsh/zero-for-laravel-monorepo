<?php
declare(strict_types=1);
// Generated controller mutation registrations.
return static fn (\ZeroForLaravel\Metadata\ModelRegistry $models, \ZeroForLaravel\Http\ControllerRouteDispatcher $routes): \ZeroForLaravel\Mutation\ControllerMutationRegistry => new \ZeroForLaravel\Mutation\ControllerMutationRegistry($models, $routes, [
'm_cc56b27d8db39835819de5f22876518d' => ['contract' => array (
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
  'version' => 'cc56b27d8db39835819de5f22876518d741b320a5a61cdafc1d0cd99de0b7285',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_4bf80a90e9fbeb89a07eb4dbadb284aa' => ['contract' => array (
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
  'version' => '4bf80a90e9fbeb89a07eb4dbadb284aa2198a6e8c53c11e990870bf8aad288a5',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('delete', $todo); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_898bc883607f37899248c48fb28bd263' => ['contract' => array (
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
  'version' => '898bc883607f37899248c48fb28bd2637c98ec248f4ef5e27768813c5d240c74',
  'returnType' => 'array',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('create', \App\Models\Todo::class); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_8c191fc2ea748f190ec8d4156f063758' => ['contract' => array (
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
  'version' => '8c191fc2ea748f190ec8d4156f0637584bbade5aeed2912ddc0079b5fde2a98a',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('update', $todo); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_0aecc5591757aad0b1f84adb4179d0b9' => ['contract' => array (
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
  'version' => '0aecc5591757aad0b1f84adb4179d0b989b60d58981ebce1d5be0c48236a3de4',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('delete', $list); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_faba244a9ab11dcee9d07095e73fbc87' => ['contract' => array (
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
  'version' => 'faba244a9ab11dcee9d07095e73fbc8738d0c04a9ba5fe2777d83a8000d85251',
  'returnType' => 'array',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('create', \App\Models\TodoList::class); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_6020f1e50a2fc0b3593a4a1e578a4daa' => ['contract' => array (
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
  'version' => '6020f1e50a2fc0b3593a4a1e578a4daa09185645d806f5fe20c4c06f58d44516',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('update', $list); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
]);
