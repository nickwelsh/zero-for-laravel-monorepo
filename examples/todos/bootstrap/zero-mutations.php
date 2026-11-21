<?php
declare(strict_types=1);
// Generated controller mutation registrations.
return static fn (\ZeroForLaravel\Metadata\ModelRegistry $models, \ZeroForLaravel\Http\ControllerRouteDispatcher $routes): \ZeroForLaravel\Mutation\ControllerMutationRegistry => new \ZeroForLaravel\Mutation\ControllerMutationRegistry($models, $routes, [
'm_01a1b7de4a0bd25b2b86ad242cb39003' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\ShareListController@destroy',
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
  ),
  'operations' =>
  array (
  ),
  'sites' =>
  array (
  ),
  'version' => '01a1b7de4a0bd25b2b86ad242cb39003dccd76035b7b5431536b07b02a6ed096',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_035ff3eaa2e85a3e2b77524e42d1749e' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\ShareListController@store',
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
  ),
  'operations' =>
  array (
  ),
  'sites' =>
  array (
  ),
  'version' => '035ff3eaa2e85a3e2b77524e42d1749e364edef3f0c122e3738e519b7c4f2433',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_be9b60fa04f7e5c9cb8f2fd78eee38c9' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoController@destroy',
  'optimism' => 'disabled',
  'conceal' => true,
  'returns' => false,
  'parameters' =>
  array (
    'todo' =>
    array (
      'kind' => 'model',
      'type' => 'App\\Models\\Todo',
    ),
  ),
  'operations' =>
  array (
  ),
  'sites' =>
  array (
  ),
  'version' => 'be9b60fa04f7e5c9cb8f2fd78eee38c943da9b6739e6dfe3ee7668daf43a7ad5',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_b197eac8dbc761cb5b20323220612616' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoController@store',
  'optimism' => 'disabled',
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
  ),
  'sites' =>
  array (
  ),
  'version' => 'b197eac8dbc761cb5b20323220612616db41422754b83f4cc0821afbabe61032',
  'returnType' => 'array',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_df5cb0f85c49e9e9538c310d5c971b16' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoController@update',
  'optimism' => 'disabled',
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
  ),
  'operations' =>
  array (
  ),
  'sites' =>
  array (
  ),
  'version' => 'df5cb0f85c49e9e9538c310d5c971b1666676179d18c142e97df5717f26d4f56',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_97734ef87866f3d5e44ab7d7686c5435' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoListController@destroy',
  'optimism' => 'disabled',
  'conceal' => true,
  'returns' => false,
  'parameters' =>
  array (
    'list' =>
    array (
      'kind' => 'model',
      'type' => 'App\\Models\\TodoList',
    ),
  ),
  'operations' =>
  array (
  ),
  'sites' =>
  array (
  ),
  'version' => '97734ef87866f3d5e44ab7d7686c5435a94fc528678b77fe94bfa2acc007a8d0',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_477971a45fdd403c1243cdcfeb1a5425' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoListController@store',
  'optimism' => 'disabled',
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
  ),
  'sites' =>
  array (
  ),
  'version' => '477971a45fdd403c1243cdcfeb1a5425b01e25fd085f3caa209d23adf1a21b0c',
  'returnType' => 'array',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_393c6f49ab702f21be9ccb6cf25898d2' => ['contract' => array (
  'action' => 'App\\Http\\Controllers\\TodoListController@update',
  'optimism' => 'disabled',
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
  ),
  'operations' =>
  array (
  ),
  'sites' =>
  array (
  ),
  'version' => '393c6f49ab702f21be9ccb6cf25898d24b8064b061bb3a0ad7451ee4ab645dc9',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
]);
