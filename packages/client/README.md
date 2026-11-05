# @nickwelsh/zero-for-laravel

Controller-first Laravel queries and mutations on native Rocicorp Zero **1.11.3**.
Use **v0.7.0** with the matching `nickwelsh/zero-for-laravel` Composer package.
Generate and deploy the PHP and TypeScript contracts together. React and Inertia are optional peers;
the `/inertia` adapter requires Inertia 3 and `@inertiajs/react` ^3.8.0.

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
Rows are plain decoded data:

```tsx
import type { QueryData } from '@nickwelsh/zero-for-laravel/react';
import type { index } from '@/zero/App/Http/Controllers/DashboardController';

export default function Dashboard({ lists }: { lists: QueryData<typeof index.lists> }) {
    return lists.data.map((list) => <p key={list.id}>{list.name}</p>);
}
```

Live props distinguish loading, success, refreshing, stale, failure, and idle
states. Connection status is separate from query completion. Infinite queries grow
one live window; cursor pages keep raw ordering values to avoid rounding timestamps.

For editable FormRequest inputs, type injected props as `QueryData<typeof index.lists>`
from `/react`. `lists.inputs.values` holds current inputs; `options` holds finite
enum/`in` choices; `set(partial)` validates and resubscribes; `reset()` restores the
page's inputs. Route arguments and trusted context are not editable. URL updates
are opt-in through `createInertiaAdapter`'s `url` mapping and `history` option.
The optional `ZeroForLaravel\WayfinderServiceProvider` customizes Wayfinder
`dev-next` page types with `QueryData`; Zero itself emits no global namespace.

No global page types are generated. The same named query works with native Zero:
`zero.run(index.lists(input))` or `useQuery(index.lists(input))`. For decoded live
data, use `useData(index.lists.live(input))`. `InferQueryInput` and `InferQueryResult`
describe native calls; `InferLiveQueryResult` describes decoded rows. The generated
`.react.ts` exports a schema-typed `useZero` returning the native instance.

## Controller mutations

```tsx
import { useControllerMutation } from '@nickwelsh/zero-for-laravel/react';
import { store as create } from '@/zero/App/Http/Controllers/TodoController';

const createTodo = useControllerMutation(create);
const saved = await createTodo({ list_id: list.id, title: 'A new task' });
console.log(saved.id); // Validated authoritative result, not just local prediction.
```

For native lifecycle access, use `zero.mutate(actions[create.action].prepare(input))`
with `actions` from the generated provider. Outside React, construct Zero with
`mutators` and use `actions` from the same `createControllerMutationBindings` call.
Infer business input and decoded server output with `InferMutationInput<typeof create>`
and `InferMutationResult<typeof create>`. Native results remain success/error envelopes;
parse successful server data with `create.serverResultSchema` when bypassing the hook.

Use a bound route argument map for update/delete actions. The hook exposes pending,
local application, server acceptance, field errors, and committed-result decoding
errors separately. Unmounting does not cancel or undo an enqueued native mutation.

For native lifecycle control, obtain a binding from the generated binding factory
and call `binding.prepare(input, routeArguments, preparation)`, then
`zero.mutate(request)`. Await `.client` for local prediction and `.server` for the
authoritative outcome. Parse data with `clientResultSchema` or `serverResultSchema`;
the two contracts can intentionally differ. Preparation captures IDs and time once.

These **prepared fields** are separate from required form input. Model traits select
UUID/ULID generation and Eloquent timestamp settings select the time columns.
Override `uuid`, `ulid`, and `now` globally through `createZeroBindings(preparation)`
or per hook with `useControllerMutation(create, route, preparation)`. Custom-optimism
apps pass projections first: `createZeroBindings(projections, preparation)`.

Custom optimism is passed to the generated binding factory; disabled optimism is
an explicit no-op client recipe using the same native protocol. Unsupported code
in generated mode fails generation rather than silently disabling optimism.

## Formbraid forms

React 19 apps using Formbraid **0.3** consume generated descriptors directly:

```tsx
import { store } from '@/formbraid/zero/App/Http/Controllers/TodoListController';
import { Form } from '@/formbraid';

return <Form form={store} />;
```

Install `formbraid/laravel`, `@formbraid/core`, and `@formbraid/react` at `^0.3.0`.
Register a build-only Formbraid integration running `php artisan zero:formbraid`.
Actions with route parameters generate factories: `<Form form={update({ list: list.id })} />`.
Formbraid owns drafts, rendering, and validation; the generated callback waits for
authoritative native Zero acceptance and maps errors and typed results back to it.
No Zero-specific form hook or React wrapper is required. Formbraid's own `useForm`
remains optional for programmatic reset or other instance access.
Cancellation stops observation, not a durable write; committed result-decode
failures must not retry. The optional `/formbraid` export is generator tooling:
`createControllerForm` constructs descriptors without hooks, React, or runtime
Formbraid/Effect imports. Ordinary Zero applications need none of those form dependencies.
For query-backed options, use `ZeroOptions::query(...)` in the request's `fields()`
map with direct `OptionInput::value()`, `field(path)`, and `context(key)` operands.
Generation attaches live source bindings automatically. Reusable controls consume
Formbraid's `useFieldOptions`; the optional `/react/formbraid` adapter owns Zero subscriptions.
Selected-label resolution uses a separate optional `one()` query, independent of
the current filter. Put ZeroProvider above the form.
See the [form guide](../../apps/docs/content/docs/0.x/docs/mutations.mdx#formbraid-03).

See the [repository guide](../../README.md), [query design](../../docs/QUERY_DESIGN.md),
[mutation design](../../docs/MUTATION_DESIGN.md), and [running example](../../examples/todos/README.md).
