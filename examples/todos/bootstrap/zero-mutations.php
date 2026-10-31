<?php
declare(strict_types=1);
// Generated controller mutation registrations.
return static fn (\ZeroForLaravel\Metadata\ModelRegistry $models, \ZeroForLaravel\Http\ControllerRouteDispatcher $routes): \ZeroForLaravel\Mutation\ControllerMutationRegistry => new \ZeroForLaravel\Mutation\ControllerMutationRegistry($models, $routes, [
'm_80aaa0bb59e6ad59856c2fdb450891b4' => ['contract' => array (
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
  'version' => '80aaa0bb59e6ad59856c2fdb450891b445ae056d4da6e3826190584da2f0b1c5',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_0bcf40bb888705f85b344867b89aab04' => ['contract' => array (
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
  'version' => '0bcf40bb888705f85b344867b89aab04b5d26caabc57d1e0d38c00985a2a74ba',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('delete', $todo); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_b0c58c177425ca9dc172decf05a24d81' => ['contract' => array (
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
  'version' => 'b0c58c177425ca9dc172decf05a24d81f4a287336b4bd55089ba1a886327ccec',
  'returnType' => 'array',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('create', \App\Models\Todo::class); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_346d8c43e547e41a57fc168ff963a72f' => ['contract' => array (
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
  'version' => '346d8c43e547e41a57fc168ff963a72f7c3718a9a98353349dc0f8a964aba161',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('update', $todo); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_9d7195c6688b9e5f1503612e03b379f9' => ['contract' => array (
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
  'version' => '9d7195c6688b9e5f1503612e03b379f94ff64fc40b4a7d1fc49674dae3337318',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('delete', $list); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_ebb170b8ab48673b0b9ed95701ff999a' => ['contract' => array (
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
  'version' => 'ebb170b8ab48673b0b9ed95701ff999ae7dbdc86107b2e3738c2bfc396abbae1',
  'returnType' => 'array',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('create', \App\Models\TodoList::class); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_9885930d6180e37ae8488db1abfeebaa' => ['contract' => array (
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
  'version' => '9885930d6180e37ae8488db1abfeebaa79b55b912868c4cf7e0c230eb5d40fba',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('update', $list); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
]);
