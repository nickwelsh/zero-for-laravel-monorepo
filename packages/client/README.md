# @nickwelsh/zero-for-laravel

Typed model queries, automatic optimistic CRUD, and React bindings for Zero for Laravel.
Requires the matching `nickwelsh/zero-for-laravel` Composer package and
`@rocicorp/zero` **1.10.0**. React 18/19 and FormBraid are optional peers. Query-only consumers do not need FormBraid.

Install the current stable release, **v0.4.0**, in your Laravel application:

```sh
composer require nickwelsh/zero-for-laravel
pnpm add @nickwelsh/zero-for-laravel
pnpm add --save-exact @rocicorp/zero@1.10.0
php artisan vendor:publish --tag=zero-config
```

Keep the Composer and JavaScript packages on matching release versions, and use
Zero cache **1.10.0** with the pinned Zero client.

```ts
import { Task } from './zero/generated';
import { useData, useMutation } from '@nickwelsh/zero-for-laravel/react';

const data = useData(Task.query().all());
const create = useMutation(Task.create());
create({ title: 'Hello' });
```

Use these hooks inside Zero's `ZeroProvider`. The root export is framework
independent; `/react` adds React hooks, filter controls, and the native provider.
Laravel generates the schema and allowed operations from your model definitions.

`useMutation` also returns a Formbraid descriptor. Pass it to your application's
registered `Form`; selected relations add prefixed fields and share one mutation:

```tsx
const { form } = useMutation(Party.create().with('person'));
return <Form form={form} />;
```

Formbraid owns edits and submission. The callback restores date strings to Dates,
waits for server acceptance, and maps field errors. The client uses structural producer declarations and an optional
`@formbraid/core` peer. For forms, install `@formbraid/core@^0.1.0` and `@formbraid/react@^0.1.0` for the form runtime.
Collections require an application array component; see the mutations guide for
component names and defaults.

See the [installation guide and API examples](https://github.com/nickwelsh/zero-for-laravel-monorepo#readme)
for model attributes, policies, publication setup, authenticated endpoints,
client lifetime, and supported constraints.

## v0.3.0 additions

Selections accept typed `.filter(name, value)` as well as filter objects.
`toPlain(result.data)` recursively unwraps snapshots into readonly decoded data.
`useData` preserves completed empty results, live committed sort ordering, and
stable decoding across equivalent inline selections. Infinite results distinguish
`exhausted` from `capped` and expose `stopReason`; the API grows a bounded live
window rather than traversing an entire dataset.

Explicit Laravel domain actions generate typed native Zero mutations, server-only
by default, with optional deterministic optimistic callbacks. See the
[domain mutation guide](https://github.com/nickwelsh/zero-for-laravel-monorepo/blob/main/apps/docs/content/docs/0.x/docs/domain-mutations.mdx).
