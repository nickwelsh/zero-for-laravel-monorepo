<?php
declare(strict_types=1);
// Generated controller mutation registrations.
return static fn (\ZeroForLaravel\Metadata\ModelRegistry $models, \ZeroForLaravel\Http\ControllerRouteDispatcher $routes): \ZeroForLaravel\Mutation\ControllerMutationRegistry => new \ZeroForLaravel\Mutation\ControllerMutationRegistry($models, $routes, [
'm_3439551a925d171fa3f8a37c940e63b3' => ['contract' => array (
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
  'version' => '3439551a925d171fa3f8a37c940e63b32fe13c8aed801fe52ce47cde528d32be',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_1b2cd1117df53ae57d3ee164925c6b62' => ['contract' => array (
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
  'version' => '1b2cd1117df53ae57d3ee164925c6b6254c3b0f3e1a3d69fc4f8dbc3ef19afa1',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_77dcca5755be0fb1a99a2cb34ce3bc53' => ['contract' => array (
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
  'version' => '77dcca5755be0fb1a99a2cb34ce3bc530a6ac575eee5ba4ca7d23074abe54300',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_4db8b12df7c8e209f0c07c08e677d6a3' => ['contract' => array (
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
  'version' => '4db8b12df7c8e209f0c07c08e677d6a34035cc364f8d70bfe840dc4780abecbf',
  'returnType' => 'array',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_f267bc7741a50fbef6024dcd0f9602d5' => ['contract' => array (
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
  'version' => 'f267bc7741a50fbef6024dcd0f9602d570dc6e62677fe283c62ad1f3ec5b03a1',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_8304615915f3b301c7f745ec6692b02e' => ['contract' => array (
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
  'version' => '8304615915f3b301c7f745ec6692b02e9d3803a8890bdbff1097911fbe3aa693',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
'm_2469dc0ac4a1cd1c6fe49d8e5e529276' => ['contract' => array (
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
  'version' => '2469dc0ac4a1cd1c6fe49d8e5e529276a05bc6f7bbe913bba9a239d164448399',
  'returnType' => 'array',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('object', ['id' => new \ZeroForLaravel\Generation\Mutations\ValueContract('string', [], [], false, false)], [], false, false))->normalize($value)],
'm_e305f732176f617431bb82bade637738' => ['contract' => array (
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
  'version' => 'e305f732176f617431bb82bade6377383fad349e8ac0d1712e1ff83f1e0a22b2',
  'returnType' => 'void',
), 'guards' => [], 'result' => static fn (mixed $value): mixed => (new \ZeroForLaravel\Generation\Mutations\ValueContract('void', [], [], false, false))->normalize($value)],
]);
