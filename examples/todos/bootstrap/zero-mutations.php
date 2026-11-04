<?php
declare(strict_types=1);
// Generated controller mutation registrations.
return static fn (\ZeroForLaravel\Metadata\ModelRegistry $models, \ZeroForLaravel\Http\ControllerRouteDispatcher $routes): \ZeroForLaravel\Mutation\ControllerMutationRegistry => new \ZeroForLaravel\Mutation\ControllerMutationRegistry($models, $routes, [
'm_9a2016beb6e3f9b7eea8e8dfd916eaaf' => ['contract' => array (
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
  'version' => '9a2016beb6e3f9b7eea8e8dfd916eaaf63c0ca76159b69c4742fe66320b61218',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_3ec7d00e58c97dc9a75db22761e186f2' => ['contract' => array (
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
  'version' => '3ec7d00e58c97dc9a75db22761e186f26b8f0066723ad62ee7aa1b6e29d3ef0a',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_99ce22fc3246831e59df058def11c14e' => ['contract' => array (
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
  'version' => '99ce22fc3246831e59df058def11c14ecdc83c22dafb9d255919cf27f15b0ae6',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_da68eff8296c7d685a5cc7471bb6a07a' => ['contract' => array (
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
  'version' => 'da68eff8296c7d685a5cc7471bb6a07a35adaf190f045152f2a22eb0060e2d02',
  'returnType' => 'array',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_8a1a8baad3e754456e51883db2990231' => ['contract' => array (
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
  'version' => '8a1a8baad3e754456e51883db2990231a75da7f8057aa893ecd0b0ee3a0ed853',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_e7f494d11025bc4a9529622e4fbafd5b' => ['contract' => array (
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
  'version' => 'e7f494d11025bc4a9529622e4fbafd5b520d1ec28b7b4dcc13a08838b1b644ac',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_35a9e423bd9a846252db066fc8aadba0' => ['contract' => array (
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
  'version' => '35a9e423bd9a846252db066fc8aadba029f353e95f016115830974e95f941aa1',
  'returnType' => 'array',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_7f47962d8c723a4d6634d27093b7bad7' => ['contract' => array (
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
  'version' => '7f47962d8c723a4d6634d27093b7bad70430d530d7acdb035482486747e69f13',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
]);
