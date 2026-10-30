# @nickwelsh/zero-for-laravel

Controller-first Laravel queries and mutations on native Rocicorp Zero **1.11.3**.
This is an unreleased pre-alpha rewrite. Use matching workspace/path revisions of
this package and `nickwelsh/zero-for-laravel`; backwards compatibility is not a goal.

Laravel owns controller discovery, FormRequests, binding, authorization, and
authoritative writes. Zero owns replication, subscriptions, optimistic transactions,
replay, and persisted mutation outcomes. There is no separate client queue or
mutation-result endpoint.

## Inertia pages

Run `php artisan zero:generate --react` in the application. Install the generated
query bindings in the page resolver:

```tsx
import { createInertiaAdapter } from '@nickwelsh/zero-for-laravel/inertia';
import { bindings } from '@/zero/generated.queries';

const livePages = createInertiaAdapter(bindings);
// Inside createInertiaApp's resolver, after loading the page component:
return livePages.wrap(module.default, page);
```

Put the generated `ZeroProvider` in the authenticated persistent layout, outside
the wrapped page. Supply the authenticated user ID and trusted-context counterpart.
The adapter injects declared live props; pages do not call `useQuery` for them.
Rows are plain decoded data, not model snapshots:

```tsx
import type { Pages } from '@/zero/generated.queries';

export default function Dashboard({ lists }: Pages['App\\Http\\Controllers\\DashboardController@index']) {
    return lists.data.map((list) => <p key={list.id}>{list.name}</p>);
}
```

Live props distinguish loading, success, refreshing, stale, failure, and disabled
states. Connection status is separate from query completion. Infinite queries grow
one live window; cursor pages keep raw ordering values to avoid rounding timestamps.

## Controller mutations

```tsx
import { useControllerMutation } from '@nickwelsh/zero-for-laravel/react';
import { actions } from '@/zero/generated.react';

const create = useControllerMutation(actions['App\\Http\\Controllers\\TodoController@store']);
const saved = await create({ list_id: list.id, title: 'A new task' });
console.log(saved.id); // Validated authoritative result, not just local prediction.
```

Use a bound route argument map for update/delete actions. The hook exposes pending,
local application, server acceptance, field errors, and committed-result decoding
errors separately. Unmounting does not cancel or undo an enqueued native mutation.

For native lifecycle control, call `action.prepare(input, routeArguments)`, then
`zero.mutate(request)`. Await `.client` for local prediction and `.server` for the
authoritative outcome. Parse data with `clientResultSchema` or `serverResultSchema`;
the two contracts can intentionally differ. Preparation captures IDs and time once.

Custom optimism is passed to the generated binding factory; disabled optimism is
an explicit no-op client recipe using the same native protocol. Unsupported code
in generated mode fails generation rather than silently disabling optimism.

See the [repository guide](../../README.md), [query design](../../docs/QUERY_DESIGN.md),
[mutation design](../../docs/MUTATION_DESIGN.md), and [running example](../../examples/todos/README.md).
