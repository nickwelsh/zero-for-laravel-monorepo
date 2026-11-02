<?php
declare(strict_types=1);
// Generated controller mutation registrations.
return static fn (\ZeroForLaravel\Metadata\ModelRegistry $models, \ZeroForLaravel\Http\ControllerRouteDispatcher $routes): \ZeroForLaravel\Mutation\ControllerMutationRegistry => new \ZeroForLaravel\Mutation\ControllerMutationRegistry($models, $routes, [
'm_dcb46793e910089df22156fc22391269' => ['contract' => array (
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
  'version' => 'dcb46793e910089df22156fc223912694443c5af58ada5cfb9d0b0854186a0b4',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_3db96884c449c1526ca129ad23a92d91' => ['contract' => array (
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
  'version' => '3db96884c449c1526ca129ad23a92d919229df38e21592944cacf3fb2503afc7',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('delete', $todo); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_56120a14c922034ed80ac4c6e8fbffbd' => ['contract' => array (
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
  'version' => '56120a14c922034ed80ac4c6e8fbffbdc53be11319899f3f6b79a65c3553c0b3',
  'returnType' => 'array',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('create', \App\Models\Todo::class); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_62ec8cea799b02bd5ef6ab18d6ef1b4b' => ['contract' => array (
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
  'version' => '62ec8cea799b02bd5ef6ab18d6ef1b4bb85e323857cdaed2336956cb2f039447',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('update', $todo); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_d130a045794ac87790e8e91a4cd02813' => ['contract' => array (
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
  'version' => 'd130a045794ac87790e8e91a4cd028130bc635cba7354d3d540ac0fad1fc04fc',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('delete', $list); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_2e5d81423675e0e2eaa7b0d20a7ab665' => ['contract' => array (
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
  'version' => '2e5d81423675e0e2eaa7b0d20a7ab66548e4dc63b68dbe9c64002d5610f757b9',
  'returnType' => 'array',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('create', \App\Models\TodoList::class); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_165bb13b1eca8466b1bacb24022c1381' => ['contract' => array (
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
  'version' => '165bb13b1eca8466b1bacb24022c1381ed981367671b3f0e571918f02f7a3a97',
  'returnType' => 'void',
), 'guards' => [static function (array $__scope): void { extract($__scope, EXTR_SKIP); \Illuminate\Support\Facades\Gate::authorize('update', $list); }], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
]);
