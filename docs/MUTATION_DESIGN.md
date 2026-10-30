# Zero for Laravel: Mutation Design

Status: architectural design baseline; implementation has not started. Public API spellings and the implementation questions in section 17 remain open.

This document specifies controller-first mutations for the proposed Zero integration. It complements [QUERY_DESIGN.md](QUERY_DESIGN.md) and incorporates the discussion of simple person creation, conditional related writes, and the transactional issue-assignment example from Zero's zbugs application.

Examples describe target behavior. Attributes such as `ZeroMutation` and `ZeroShared`, mutation context methods, generated imports, and configuration syntax are illustrative, not existing APIs in this sketch application. Example models, fields, enum cases, policies, and relationships describe representative application code. They are not assertions about this application's current schema.

## 1. Decisions and scope

| Area                    | Design                                                                                                                                                                                     |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Declaration             | Explicitly opt a controller action into Zero mutation handling. Do not expose every controller method or infer permission to invoke it from its name.                                      |
| Return contract         | A mutation returns nothing, or an explicitly JSON-compatible value. It does not return a redirect or Inertia page response.                                                                |
| Native results          | Use Zero's native client/server result data and persisted authoritative result mechanism. No separate result-read endpoint is required by this design.                                     |
| Transport               | Submit through the native Zero mutation protocol, preserving its queuing, replay, ordering, and reconciliation behavior.                                                                   |
| Authoritative execution | PHP owns validation, authorization, database persistence, and server-only behavior.                                                                                                        |
| Optimistic execution    | Native Zero transactions apply an explicitly selected optimistic recipe. The browser predicts; it does not authorize the operation.                                                        |
| Simple syntax           | Support recognized Eloquent CRUD statements as ergonomic input to a bounded compiler.                                                                                                      |
| Internal operations     | Lower supported code into a shared semantic representation of transaction reads, writes, expressions, and calls.                                                                           |
| Reusable logic          | Explicitly shared PHP helpers may call other supported shared helpers; codegen checks the complete reachable dependency set.                                                               |
| Complex operations      | Allow generated optimism, a custom optimistic projection, or disabled optimism without creating separate mutation engines.                                                                 |
| Authorization           | Keep Laravel Gates, policies, and FormRequest authorization authoritative. Portable client checks are optional predictions.                                                                |
| Binding                 | Preserve real Laravel route model binding. Reload and reauthorize state inside the authoritative transaction when the operation depends on current state.                                  |
| Atomicity               | All database writes in one mutation, its protocol progress, and any persisted authoritative outcome commit consistently on the configured connection.                                      |
| Side effects            | Browser replay never sends mail, calls services, dispatches jobs, or performs other external effects. Important external work uses durable transactional intent and idempotent processing. |
| Schemas and casts       | Reuse the query design's validator-independent contracts, initial Zod generation driver, and public/wire encoding boundaries.                                                              |

This is not a general PHP-to-JavaScript compiler, a replacement for Laravel authorization, a distributed transaction system, or an attempt to recreate Eloquent in the browser.

## 2. Controller experience

### 2.1 Simple create with no result

The target supports the basic shape of the existing `store` example:

```php
#[ZeroMutation]
public function store(PersonStoreRequest $request): void
{
    Person::create($request->validated());
}
```

The compiler recognizes the validated input and supported create operation, generates its optimistic equivalent, and registers authoritative PHP execution. The server still applies the configured Eloquent behavior and the action's validation and authorization.

A declaration does not grant the browser permission to create arbitrary models or write arbitrary fields. The server executes this registered action with its validated arguments.

### 2.2 Returning JSON

```php
/** @return array{id: string} */
#[ZeroMutation]
public function store(PersonStoreRequest $request): array
{
    $person = Person::create($request->validated());

    return ['id' => $person->id];
}
```

The return shape becomes a generated output contract. The example assumes a string primary key; generation uses the model's actual key type.

Returning an Eloquent model is not an implicit request to serialize its attributes. Authors return an explicit JSON value or a supported explicitly declared serialization contract. A PHP `JsonResponse`, `RedirectResponse`, or Inertia response is not a Zero mutation result. In particular, `back()` is not part of this mutation API.

Existing methods that return the boolean result of `update()` should deliberately choose whether that boolean is useful result data. An update does not need to return anything to be considered successful.

### 2.3 Conditional related creation

The target also supports the meaningful shape of `storeAlt`:

```php
#[ZeroMutation]
public function storeAlt(PersonStoreRequest $request): void
{
    $validated = $request->validated();
    $person = Person::create($validated);

    if (isset($validated['email']) && $validated['email'] !== '') {
        Email::create([
            'person_id' => $person->id,
            'email' => $validated['email'],
        ]);
    }
}
```

The local and server executions must associate the same person identity with the child email. Both writes form one logical mutation. If the second authoritative write is rejected, the person must not remain committed on its own. The corresponding optimistic effects reconcile through the native Zero mutation lifecycle.

This example assumes the submitted person fields are appropriate for `Person::create()`. Generation does not automatically relocate fields between models, invent relationship authorization, or bypass mass-assignment rules.

## 3. One semantic operation model

### 3.1 Lower supported syntax into owned operations

Build the implementation around a small set of explicit mutation operations, not an expanding collection of unrelated controller special cases. The operation model includes supported expressions, transaction reads, inserts, updates, deletes, upserts, conditional branches, calls, authorization boundaries, and returns.

Recognized Eloquent syntax is ergonomic input to that model. An explicit form can expose it when useful:

```php
#[ZeroMutation]
public function storeAlt(
    PersonStoreRequest $request,
    Mutation $mutation,
): void {
    $validated = $request->validated();

    $person = $mutation->create(
        Person::class,
        data: $validated,
        key: 'person',
    );

    if (isset($validated['email']) && $validated['email'] !== '') {
        $mutation->create(
            Email::class,
            data: [
                'person_id' => $person->id,
                'email' => $validated['email'],
            ],
            key: 'email',
        );
    }
}
```

Here `key` identifies a logical creation site for stable preparation. The compiler can generate an equivalent site identity for recognized ordinary `create()` calls. Public spelling is not settled.

The server operations use real Eloquent persistence where that is the declared behavior. Generated client operations use native Zero transaction reads and writes. The client does not acquire an Eloquent runtime or arbitrary model methods.

### 3.2 Preserve authoritative behavior

Generating a TypeScript create alone is insufficient. Authoritative execution must consume the same prepared identities and execute the intended PHP behavior once. An untouched `Person::create($validated)` does not automatically pick up a framework-prepared ID that is not present in `$validated`.

Choose an explicit lowering/instrumentation strategy that accounts for this. Generated PHP execution and generated client execution must derive from the same semantic definition; they must not become independently maintained business implementations. The generated server path must not execute a lowered write and then execute the original write again.

Arbitrary PHP inside an explicitly server-only region can remain PHP. The compiler still checks any values or control dependencies crossing into shared execution.

### 3.3 Supported PHP and diagnostics

Support a defined subset covering validated input access, local assignments, literals, supported array operations, strict comparisons, null checks, boolean expressions, branching, bounded loops, supported model-key/property access, transaction operations, known authorization calls, shared-helper calls, and JSON returns.

PHP coercion and JavaScript coercion are not interchangeable. Preserve missing values, explicit null, false, zero, the string `"0"`, string operations, and numeric constraints. The query and mutation compilers should share equivalent expression semantics rather than diverge.

Unsupported dependencies fail generation with the file, action/helper, source location, and unsupported operation. Do not silently omit statements, turn unknown calls into server-only code, or claim that an approximate translation is equivalent.

## 4. Selecting optimistic behavior

Every registered action has one explicit optimism strategy. Configuration spelling remains open.

| Strategy          | Behavior                                                                                                                                                                      |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Generated         | Compile the supported shared behavior. Unsupported code is an error until an explicit boundary or supported declaration resolves it.                                          |
| Custom projection | Associate an explicitly authored optimistic implementation with the authoritative action. It predicts the useful local effect and may intentionally omit server-only details. |
| Disabled          | Enqueue the same typed intent with no speculative database writes. PHP performs the authoritative work.                                                                       |

All three strategies share invocation, schemas, native protocol handling, results, errors, and form lifecycle. A complicated action does not require switching to a second mutation transport.

A custom projection can be portable PHP using shared operations or explicitly registered native TypeScript. It is a deliberate prediction, not a second authorization implementation. Its input and any returned client data need declared contracts.

Generated mode must not silently switch to custom or disabled behavior because analysis was difficult. Runtime fallback for an explicitly declared missing-cache prerequisite is different: it is part of the selected optimistic recipe's documented semantics.

## 5. Preparation, schemas, and stable values

### 5.1 Public input and wire input

Reuse the query design's input contract and code-generation driver boundary. Zod is the initial schema output; the core operation model does not depend on Zod internals.

The public binding validates and normalizes application input, encodes cast-aware values, allocates required stable metadata, and then constructs the native mutation request with JSON-compatible arguments. Dates must be encoded before native request creation, not only transformed inside the mutator validator.

The authoritative server validates those arguments, runs required Laravel validation and authorization with correct context, and applies authoritative input preparation. FormRequest validation rules and normalization are not assumed to be fully translatable. Database-dependent rules and arbitrary custom rules remain enforced in PHP.

Framework metadata is separate from business input. Generated record IDs and replay timestamps must not accidentally become mass-assignable request fields or permit overriding protected ownership, tenant, or foreign-key values.

The browser submits a registered operation and arguments. It does not submit an authoritative list of table writes, arbitrary PHP method names, or a trusted mutation AST.

### 5.2 Stable identities

Allocate UUID/ULID identities once per mutation invocation and logical creation site. Reuse them for initial local execution, rebase, retry, and authoritative persistence. A child record's foreign key refers to the same parent identity in both executions.

For repeated creation in a supported loop, associate identities with stable item keys. A replay-dependent traversal position is insufficient if the iteration set or order can change. Reject ambiguous identity allocation rather than pairing IDs by accidental call order.

No fresh random values, clocks, or browser state may be read during replay to regenerate identities. A supported deterministic derivation from a prepared seed is an alternative only if its identity and collision semantics are explicitly defined and tested.

Validate framework-supplied IDs on the server. Their presence does not authorize creating or modifying the referenced record.

Database-generated integer primary keys are initially compatible with authoritative creation without optimistic row insertion. Do not assume negative temporary IDs or add schema migrations automatically. Temporary-to-canonical remapping across relationships and later pending mutations is a separate capability, not part of the baseline design.

### 5.3 Timestamps and field ownership

Prepare stable timestamps once when shared behavior requires them. Replaying an assignment must not continuously advance its modification time or create a different notification preference timestamp.

Distinguish a stable speculative timestamp from an authoritative security or audit value. The server may own particular timestamps and reconcile them later. Never use a client-supplied timestamp to establish identity, permissions, or an authoritative ordering rule that requires trusted server time.

Model metadata and explicit contracts determine defaults and casts that can be predicted. Server defaults, triggers, observers, and supported normalization may produce additional authoritative changes; synchronization supplies those final values.

## 6. Mutation lifecycle and atomicity

```text
Typed application input
  -> validation, encoding, and stable preparation
  -> one native Zero mutation request
  -> local optimistic transaction, if applicable
  -> queued push to the registered PHP mutation handler
  -> authoritative context, binding, validation, and authorization
  -> authoritative transaction reads and writes
  -> outcome/progress persistence and commit
  -> native result delivery and data synchronization
  -> rebase/reconciliation of remaining pending mutations
```

Native Zero owns optimistic transaction execution and replay. Do not implement a second optimistic cache or a parallel browser queue.

On the configured database connection, all authoritative writes and native mutation progress/outcome handling must remain consistent under success, rejection, retry, duplicate delivery, and process failure. A rejection must not leave half the business writes committed. A retry after a lost response must not repeat an already committed create.

Use the supported Zero version's mutation identity and protocol state, bound to the correct authenticated application context. Do not substitute an unauthenticated browser-provided idempotency key for the protocol's ownership checks. Business-level duplicate submissions with different mutation identities may additionally require application constraints or idempotency; protocol deduplication does not make them the same operation.

Transactions cover their configured connection. Writes to another database or external service are not made atomic by nesting them inside the PHP callback. Reject unsupported cross-connection assumptions or require an explicit application workflow.

Native client-side failure and a deliberate no-op optimistic recipe are different. The latter must still enqueue the valid intent. Do not signal unavailable local data by throwing a native mutation error that prevents authoritative execution.

## 7. Shared transaction-aware helpers

### 7.1 Checked shared call graph

An illustrative declaration is:

```php
#[ZeroShared]
final class IssueSubscriptions
{
    public function subscribeUnlessPreferenceExists(
        Mutation $mutation,
        string $issueId,
        string $recipientId,
    ): void {
        // Implementation uses supported transaction operations.
    }
}
```

The empty body above is a signature example, not a valid implementation or a claim that the behavior can be omitted. Section 8 specifies the helper's required behavior.

Codegen analyzes the methods actually called by a mutation and their reachable dependencies. An attribute is not a blanket assertion that any code inside the class is portable. Reject unsupported dynamic dispatch, recursion, service dependencies, or side effects unless explicitly supported.

The shared helper receives the mutation context so all reads use the transaction's current state. It does not read a React hook result, a detached browser cache snapshot, or an unrelated Eloquent query outside the authoritative transaction.

Server dependency injection can continue to supply the real PHP helper. Client generation follows verified shared methods and supported constructor dependencies; it does not reproduce Laravel's service container.

### 7.2 Read-your-writes behavior

A helper called after an issue update sees the updated issue within the transaction. This includes fields relevant to recipient visibility. Operation order and helper-call order are part of the business semantics, not formatting details.

Shared code may read state during every native replay. Do not freeze the original assignee or notification preference into input merely because the form happened to know it when submitting.

## 8. Complex example: issue assignment and notification preferences

### 8.1 Controller shape

The following illustrates the intended Laravel experience, using snake_case model fields:

```php
/** @return array{id: string} */
#[ZeroMutation]
public function update(
    UpdateIssueRequest $request,
    Issue $issue,
    Mutation $mutation,
    IssueSubscriptions $subscriptions,
): array {
    $issue = $mutation->reloadForUpdate($issue);

    Gate::authorize('update', $issue);

    $change = $request->validated();
    $previousAssigneeId = $issue->assignee_id;

    $assigneeChanged = array_key_exists('assignee_id', $change)
        && $change['assignee_id'] !== $previousAssigneeId;

    $mutation->update($issue, $change);

    if (
        array_key_exists('assignee_id', $change)
        && $change['assignee_id'] !== null
    ) {
        $subscriptions->subscribeUnlessPreferenceExists(
            $mutation,
            $issue->id,
            $change['assignee_id'],
        );
    }

    if ($assigneeChanged && $previousAssigneeId !== null) {
        $subscriptions->unsubscribe(
            $mutation,
            $issue->id,
            $previousAssigneeId,
        );
    }

    return ['id' => $issue->id];
}
```

`reloadForUpdate()` denotes an authoritative same-transaction reload and lock, followed by access to the loaded record. Its generated client equivalent reads the native mutation transaction; it does not claim to lock the server. Its prerequisite handling for a missing local issue is specified in section 9.

The action's binding and validation/error configuration must conceal private-resource existence as described in section 10. The example does not imply that a policy call after ordinary binding automatically achieves that property.

### 8.2 Behavior that must be preserved

| Submitted assignee          | Required issue/preference behavior                                                                                               |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Key absent                  | Preserve the current assignee; do not run assignee-related preference changes.                                                   |
| Explicit null               | Clear the assignee; unsubscribe the prior assignee if there was one.                                                             |
| Same non-null assignee      | Keep the assignment and still run the subscribe-unless-preference-exists helper, matching the supplied example.                  |
| Different non-null assignee | Assign the new user, attempt the new user's subscription under the helper's rules, and unsubscribe the previous user if present. |

Capture the old assignee before applying the update. A hydrated Eloquent model is mutable; reading its property afterward is not a reliable old-state read. `isset()` is not sufficient to distinguish an omitted assignee from explicit null.

Each preference helper checks whether its recipient can see the issue using transaction state at that point in the operation. That recipient can differ from the actor performing the mutation. A failed required check rejects the authoritative operation and rolls back the issue write as well as any earlier preference write.

### 8.3 Preference helper contract

The helpers update subscription preference rows. They do not send external notifications.

| Helper                             | Authoritative behavior                                                                                                                                                           |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Subscribe unless preference exists | Verify recipient visibility. Read the preference for `(recipient, issue)`. If it exists, preserve it, including an explicit opt-out. Otherwise create the subscribed preference. |
| Unsubscribe                        | Verify recipient visibility. Upsert an explicit unsubscribed preference for `(recipient, issue)`, using the mutation's specified stable timestamp semantics.                     |

If a force-update capability is exposed later, it must be explicit and authorized. It must not silently replace the subscribe-unless-present contract.

The source example writes preference timestamps from its prepared modification time. Our shared helper must similarly use a declared stable value, not call the current clock each time it replays.

Preserve a unique identity for `(recipient, issue)` and handle concurrent creation safely. An unprotected read followed by an unconditional upsert could overwrite a concurrently created opt-out. Use a constraint and an authoritative conditional insert/conflict strategy that preserves the declared behavior. The native client prediction must not promise stronger concurrency guarantees than the server actually implements.

### 8.4 Rebase and concurrent reassignment

Suppose a pending mutation originally observes A as the assignee and predicts a change to B. An authoritative change assigns C before that mutation is accepted. On native rebase, the shared code reads the new transaction state and recomputes which previous assignee to unsubscribe. It does not keep an obsolete A-to-B write list supplied by the form.

On the server, serialize conflicting updates using an appropriate lock or equally strong explicit concurrency strategy. Reauthorize against the authoritative state used for the update. Reloading the record outside the transaction and then computing preference writes from that detached state is insufficient.

## 9. Incomplete local data and deliberate fallback

### 9.1 Absence in cache is not absence in the database

A local transaction may not have the issue, a recipient's user row, or an existing subscription preference. A native query returning no local row is not automatically authoritative proof of nonexistence.

Distinguish the recipe's data prerequisites from server business-rule failures. The generated layer needs an explicit way to decline prediction when prerequisites are unavailable; exact API spelling remains open.

For the complex example, a conservative prediction can update an available issue while intentionally deferring preference changes whose required state is unknown. This is an explicitly chosen projection. It is not permission for the compiler to skip any statement that encounters an inconvenient value.

Do not assume a transaction read exposes a reliable completeness proof unless the selected native API actually supplies one. An earlier UI query's completion flag is not automatically sufficient proof during later replay.

### 9.2 Safe local execution boundaries

| Local situation                                       | Behavior                                                                                                                |
| ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Required issue unavailable before prediction          | Skip the optimistic recipe and enqueue the valid intent.                                                                |
| Issue available, preference prerequisites unavailable | An explicitly declared narrower helper projection may omit preference prediction while preserving the issue prediction. |
| Existing opt-out available                            | Preserve it during subscribe-unless-present prediction.                                                                 |
| Relevant data available during rebase                 | Re-evaluate supported state-dependent behavior against the current transaction.                                         |
| Authoritative business-rule rejection                 | Native reconciliation removes/rebases speculative effects and reports the server error.                                 |

Determine fallback before performing the dependent writes. Catching a private sentinel after several `tx.mutate` calls and returning normally does not roll those writes back. Conversely, throwing an ordinary native error may prevent the mutation from being submitted.

The initial implementation should support pre-write prerequisites and explicit helper projections. If a generated recipe needs an unsupported late rollback/fallback boundary, reject that optimism strategy and require a custom projection or disabled optimism. Do not invent an independent staging/cache engine or assume native savepoints exist.

## 10. Laravel authorization and real model binding

### 10.1 Authoritative Gates and policies

Use Laravel's existing policy discovery/registration, Gate authorization, policy filters, and FormRequest authorization. A typical policy remains recognizable:

```php
use Illuminate\Auth\Access\Response;

public function update(User $user, Issue $issue): Response
{
    return $user->id === $issue->creator_id
        ? Response::allow()
        : Response::denyAsNotFound();
}
```

Administrator behavior may use a normal policy `before()` method. The server adapter must execute the real configured policy with the current authenticated actor. It cannot trust a role, user ID, tenant ID, or permission result supplied in mutation input.

Recognize authorization as an explicit execution boundary. A server guard may reject an otherwise predictable mutation; it does not need to be portable for that action to have optimistic behavior. Authorization checks must not be optimized away because codegen cannot translate the policy body.

### 10.2 Optional portable policy checks

Portable checks may be generated when explicitly selected and when the required policy logic and data are supported. They improve local feedback and can inform an optimistic projection. They are not a security boundary.

Arbitrary policies can depend on services, database state, permission packages, or secrets. The client must not receive secrets or unconstrained protected state merely to execute those checks. An unsupported policy stays authoritative PHP; it does not prevent all optimism by default.

Policy filters, `Gate::before`/`after` behavior, additional context, guest handling, and checks made for a recipient rather than the actor need explicit treatment before claiming parity for portable authorization. Do not compile one policy method and imply that all configured Gate behavior was reproduced.

Keep this bridge inside Zero for Laravel initially. A separate authorization package is not required to support the example; extraction can be considered later if there is an independently useful, proven abstraction.

### 10.3 Preserve binding without stale authorization

Keep normal Laravel model binding and real models for authoritative controller arguments. Do not inject incomplete proxies into policies or reuse browser snapshots as server models. This follows the normal-binding decision in the query design.

Binding can establish route keys, parent scopes, tenant restrictions, soft-delete behavior, and custom lookup rules. These constraints must remain in force for Zero endpoint invocation, including direct calls that did not visit a page first. A registered operation name is not a substitute for those checks.

If business behavior depends on current state, reload with the required constraints inside the mutation transaction, acquire appropriate locks, and authorize that version. If lock waiting or concurrent changes invalidate an earlier assumption, re-evaluate it. FormRequest authorization that ran earlier is not a reason to skip required same-transaction checks.

### 10.4 Conceal resource existence consistently

The source example authorizes before exposing a distinct existence outcome. Its authorization helper may itself read the entity internally. The invariant is that an unauthorized caller cannot distinguish a private existing resource from an unavailable resource through public results.

For sensitive actions, normalize missing-binding, forbidden, missing-record, and relevant validation outcomes to the configured generic mutation error code, message, and safe details. A generic message with different public codes still leaks the distinction. Binding's normal 404 and a later policy's 403 do not become equivalent merely because the policy uses `denyAsNotFound()` in another branch.

Required authentication and resource-access checks must happen before existence-sensitive validation feedback is exposed. Do not leak an assignee's private existence through an `exists` validation error when that information is outside the caller's permitted view.

Preserve diagnostic detail in appropriate server reporting, not in client error details. This contract concerns public errors and access control; it is not a claim of constant-time database execution.

## 11. Server-only code and side effects

### 11.1 Explicit boundaries and dependency checking

An illustrative server-only guard is:

```php
Zero::server(function () use ($issue): void {
    Gate::authorize('update', $issue);
});
```

Known Laravel authorization calls can receive equivalent handling without requiring this wrapper at every call site. Final syntax is open.

A server-only computation with a consumed result is different:

```php
$classification = Zero::server(
    fn (): string => $classifier->classify($validated),
);

$mutation->update($person, [
    'classification' => $classification,
]);
```

The client does not know that result. The dependent write needs an explicit prediction, an explicitly narrower projection, or server-only execution. Skipping the computation and pretending the variable exists is invalid.

Unknown server calls can also control whether subsequent writes happen. Codegen tracks control dependencies as well as values. An explicitly declared authorization guard can be assumed to succeed speculatively, subject to authoritative rejection; an arbitrary condition is not automatically such a guard.

### 11.2 Eloquent events, casts, and defaults

Real server persistence retains declared Eloquent casts, mass-assignment protections, observers, and applicable lifecycle behavior. Portable metadata can predict supported casts and defaults. Arbitrary accessors, observer-derived fields, database triggers, and service calls are not automatically shared code.

An optimistic row may differ temporarily from the authoritative result. Additional server-produced changes arrive through native synchronization. If hidden behavior makes a useful prediction impossible, select an explicit projection or disabled optimism rather than claiming complete Eloquent emulation.

### 11.3 External effects

Sending messages, making network requests, dispatching work, updating files, and similar effects never execute during browser replay. They must not be repeated merely because a server transaction retried either.

Laravel's after-commit facilities are useful for ordinary deferred dispatch. They do not by themselves guarantee that work is durably delivered if the process fails between database commit and dispatch. For important delivery, persist an outbox/work-intent record atomically with the business mutation and process it with idempotent workers. External providers may also need their own idempotency keys.

Preference-row changes in section 8 are transactional database state and can be shared. Actual notification delivery is server work. Do not conflate the two because both use the word notification.

## 12. Native JSON results

### 12.1 No return, explicit null, and typed data

Zero supports a JSON return value and no return value. Preserve that distinction in the generated contract:

| PHP intent                          | Native outcome                                                |
| ----------------------------------- | ------------------------------------------------------------- |
| `void`, no result                   | Success without result data; the client observes `undefined`. |
| Explicit JSON null                  | Success with `data: null`.                                    |
| Scalar, list, or object-shaped JSON | Success with the declared JSON data.                          |

PHP normally represents falling off a function and returning null with the same runtime value. The compiler/declared return contract must carry the distinction; do not guess from the runtime value alone. Ambiguous methods require an explicit contract rather than inconsistent behavior.

PHP array serialization also distinguishes lists from objects by their keys. Generated contracts must preserve the declared JSON shape, including empty objects versus empty lists. Avoid accidentally converting a sparse list into an object. Reject unsupported values, non-finite numbers, recursion, invalid encoding, and PHP objects without an explicit supported JSON contract.

Native JSON does not contain a JavaScript `Date` instance. Result-specific codecs may expose richer public values only through an explicit declared conversion boundary. Do not automatically serialize a model or a secret-bearing DTO just because the language permits returning it.

### 12.2 Separate client and server result contracts

The optimistic execution and authoritative execution may return different values or shapes. A client can return no data when it cannot predict a server-generated value. Generated typing must not falsely promise that `.client.data` has the server's result shape.

Prefer one compatible shared output contract where possible, but allow explicitly declared client/server output contracts where needed. Generate validation for returned data, using the same schema driver boundary as inputs. Native Zero's result data is broadly typed JSON; the generated wrapper narrows it only after validation.

An abbreviated frontend example for a declared server result of `{id: string}` is:

```ts
import { personMutations } from '@/zero/generated/PersonController';

const request = personMutations.store.prepare({
    first_name: 'Ada',
    last_name: 'Lovelace',
});

const write = zero.mutate(request);
const result = await write.server;

if (result.type === 'success') {
    const saved = personMutations.store.serverResultSchema.parse(result.data);
    console.log(saved.id);
}
```

Generated module/member names are illustrative. `zero` is the configured native client. The input example assumes ownership is supplied through trusted server context or otherwise handled by the declared request; it does not override this sketch's current `user_id` validation rule.

### 12.3 Persist the authoritative result with the outcome

Use the supported native protocol's persisted server-result mechanism. The PHP implementation must forward the action's JSON value and store it consistently with its committed mutation outcome. Returning an empty success object while dropping the controller value is incorrect.

Validate/encode a returned server value before committing the operation so a serialization failure cannot accidentally yield an untracked partial success. Duplicate processing or a lost endpoint response must deliver the recorded authoritative outcome according to the native protocol; do not re-execute the business operation or reconstruct a different result by rereading mutable records later.

An invalid client-side result decode after confirmed server commit is a result/contract failure, not proof that the write rolled back. Expose that distinction. Automatic retries must not turn it into a second business submission.

### 12.4 Required upstream capability

The current official Zero documentation and inspected upstream source support JSON returns. The previously inspected installed 1.10.0 signature exposed an older `Promise<void>` contract. Implementation must select and verify a version that provides the required native return, persistence, and recovery behavior across the full client/server stack.

Do not treat the older installed API as proof that returns are unsupported, or claim a particular minimum release without verifying it. Updating dependencies is a separate authorized implementation action; this design document does not change them.

## 13. Server dispatch, failures, and retries

### 13.1 Preserve the action's Laravel boundary

The mutation registry binds known operation names to explicit controller actions and generated execution contracts. Reject unknown or unregistered operations.

The native push endpoint is not automatically the original route's HTTP pipeline. Calling a controller method through the container does not reproduce its authentication, middleware, binding, FormRequest preparation, or authorization. The adapter must deliberately preserve the required behavior and current request context.

Not every middleware is meaningful for replayed batch execution, especially middleware that redirects or depends on unrelated HTTP side effects. Define supported behavior, surface unsupported dependencies, and never silently bypass a security requirement. The exact dispatcher mechanism is an implementation decision; inventing a synthetic request without the required semantics is not a solution.

Normal routes and the native mutation endpoint must not both perform the same write for one submission. If ordinary HTTP invocation of the action is retained, define that compatibility path explicitly and ensure it shares business semantics without executing the operation twice.

### 13.2 Structured errors

Map expected failures to stable, safe application error codes and details. Validation errors retain field paths. Authorization and hidden-resource errors follow their concealment contract. Constraint conflicts receive useful safe errors where appropriate. Unexpected failures are reported server-side without returning secrets or stack traces.

Distinguish native transport/protocol failures from application rejections. Follow the native ordering and progress rules when recording a permanent rejection. Retryable database/transport failures must not accidentally advance progress as if the operation committed.

### 13.3 Retry and replay rules

Reuse the same prepared invocation for native delivery retries. Authoritative re-execution is only allowed when the native protocol determines the prior attempt did not commit. Return persisted outcomes for already-processed operations as required by that protocol.

Browser rebase can recompute reads, conditions, and predicted values against new transaction state. It cannot initiate new external effects or generate different prepared identities. Distinguish internal native retry from an explicit user resubmission with a new operation identity.

## 14. Frontend and Inertia integration

The form experience should remain familiar, but a Zero mutation submission is not an ordinary Inertia write request. A wrapper submits once through Zero, maps structured errors into form state, and exposes local application separately from authoritative completion.

| Stage                           | UI meaning                                                                                                     |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Prepared/enqueued               | Input and stable metadata are fixed for this invocation.                                                       |
| Locally applied                 | The optimistic recipe completed, possibly as an intentional no-op. The server has not necessarily accepted it. |
| Pending/queued                  | Authoritative confirmation is outstanding, including while offline.                                            |
| Server accepted                 | The authoritative mutation succeeded and its result is available through the native outcome.                   |
| Rejected                        | Show the safe application error; native reconciliation handles speculative effects.                            |
| Committed, result decode failed | Report the contract/result problem without claiming the database write failed.                                 |

Native `.server` observation covers the relevant failure outcomes defined by the selected upstream version. The adapter must not hang forever waiting on a server promise while ignoring a native local failure. Verify and use that version's behavior instead of layering contradictory promise handling over it.

Authoritative form success, success notifications, and application navigation follow server acceptance. An optional optimistic-applied callback can be exposed separately. Result-dependent navigation happens in frontend application code using generated Wayfinder helpers; the PHP mutation does not return `back()` or a redirect.

Server acceptance is not a guarantee that every active live query has already synchronized the corresponding rows. Do not assert that a result-dependent immediate cache read is authoritative merely because `.server` succeeded.

Cancelling observation, unmounting a form, or navigating away does not promise cancellation of an already queued mutation. Define pending UI and callback disposal accordingly. Do not reissue the operation just because a component remounted or an outcome was not yet visible locally.

Reuse existing Inertia/Wayfinder route metadata for links and action association. Final generated mutation helper placement and any extensions to familiar form APIs remain open; do not claim that unmodified upstream `<Form>` or `useForm` already routes writes through Zero.

## 15. Integration with the existing package

Reuse the existing owning layers for generated model metadata, cast conversion, optimistic CRUD/nested writes, native mutation submission, authoritative transactions, validation, and protocol deduplication where they meet this design.

The existing domain-operation support demonstrates actions with optional optimism. It is a starting point, not proof that controller dispatch, checked shared helpers, or all native JSON result semantics are already implemented.

The older optional result-read endpoint pattern is not the new default. Adopt native returned data for operations requiring result values and preserve compatibility deliberately if existing consumers rely on the previous API.

Keep mutation semantics independent of Inertia. Generated operations can also be used by clients outside page forms. The Inertia adapter owns form presentation and error integration, not database execution or a separate optimistic engine.

Generate coordinated client and authoritative artifacts. Changes to controller dependencies, shared helper methods, policy contracts selected for portable execution, requests, model metadata, or result contracts invalidate the relevant output. Treat pending offline mutations and old clients as an explicit deployment-compatibility concern: retain supported operation versions or reject unsupported contracts safely. Never execute a stale queued intent against incompatible new semantics silently.

## 16. Acceptance criteria

These are implementation requirements, not claims that an implementation or tests already exist.

| Area                    | Observable proof                                                                                                                                               |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Simple creation         | The supported `Person::create($request->validated())` shape generates a useful local prediction and one authoritative creation.                                |
| Conditional children    | Person/email creation shares stable identity and atomicity; a child rejection does not leave the parent committed.                                             |
| Strategy selection      | Generated, custom, and disabled optimism use the same protocol and contracts; unsupported generated code never silently changes strategy.                      |
| Shared helpers          | Reachable shared methods preserve transaction reads, calls, branches, and write order; unsupported dependencies identify their source.                         |
| PHP semantics           | Missing, null, false, zero, `"0"`, strict comparisons, and supported string/array behavior agree across executions.                                            |
| Stable preparation      | IDs, required timestamps, and keyed repeated creates remain stable across retries, local replay, and server execution.                                         |
| Protected input         | Client metadata cannot override trusted ownership, tenant context, relationship binding, or writable-field constraints.                                        |
| Public/wire schemas     | Dates and other supported casts normalize before native request creation; validators and generated types agree.                                                |
| Transaction reads       | Helpers read current transaction state, including preceding writes, rather than detached UI snapshots.                                                         |
| Local prerequisites     | Missing cache data can produce the declared no-op prediction without falsely rejecting a valid server operation.                                               |
| Safe fallback           | A skipped helper/recipe leaves only the explicitly intended speculative writes; no mid-recipe exception is swallowed to commit accidental partial effects.     |
| Assignment semantics    | Omitted, null, unchanged, and changed assignees produce the specified distinct behavior.                                                                       |
| Preference preservation | Existing opt-outs survive subscription attempts; unknown cache state is not treated as authoritative absence.                                                  |
| Recipient visibility    | Visibility checks apply to the intended recipient and see the updated issue; a required failure rolls back the action.                                         |
| Concurrent updates      | Conflicting reassignments and concurrent preference creation preserve the declared invariants with real constraints/locking or equivalent concurrency control. |
| Authorization           | Real Laravel policies, relevant filters, and FormRequest authorization run authoritatively with trusted context; optional client checks cannot bypass them.    |
| Binding                 | Real bound models and route scopes are preserved; state-dependent authorization uses an appropriately reloaded authoritative record.                           |
| Concealment             | Sensitive missing, forbidden, binding, and relevant validation failures do not expose distinguishable private-resource outcomes.                               |
| Atomic outcome          | Business writes, progress, and recorded outcome remain consistent through rejection, transaction retry, process failure, and duplicate delivery.               |
| JSON results            | Void, explicit null, scalars, lists, and object-shaped outputs retain their contracts; invalid output cannot produce an untracked committed success.           |
| Result recovery         | Losing the endpoint response still permits native delivery of the same persisted server result without re-executing the action.                                |
| Client/server results   | Different declared speculative and authoritative result shapes are validated and typed honestly.                                                               |
| Side effects            | Replay never sends external notifications; durable external work is not lost or duplicated merely because of a crash/retry.                                    |
| Frontend lifecycle      | One submission performs one logical write; local success differs from server acceptance; unmount/cancel does not invent an undo guarantee.                     |
| Deployment              | Old queued operations follow the explicit compatibility policy instead of silently acquiring new behavior.                                                     |

Use the native Zero client as the replay/transaction oracle, Laravel feature tests for the integration's endpoint and security boundaries, and real database tests for important concurrency and commit/recovery guarantees. Small expression/compiler tests can cover portable semantics. Do not treat snapshots of generated text alone as proof of atomicity or authorization, or duplicate every upstream framework test.

## 17. Remaining implementation decisions

The design is sufficient to guide implementation. These details need explicit resolution as the corresponding layer is built:

- The exact supported Zero version with verified native JSON return and persisted-outcome recovery behavior.
- Attribute/configuration spellings for registration, optimism strategy, shared methods, prerequisites, and resource concealment.
- The bounded initial PHP subset and verified Ranger/Surveyor extension mechanisms for a checked shared call graph.
- The authoritative lowering/dispatch mechanism that consumes prepared identities while preserving the required Laravel lifecycle.
- Generated helper paths, Wayfinder coordination, and the frontend form adapter's public API.
- Stable creation-site/item-key identity rules and supported repeated-create patterns.
- Explicit local prerequisite/no-op projection contracts, especially when optional related state is absent from cache.
- Which policy constructs may optionally be portable and how all required Gate behavior is represented or left authoritative.
- Result shape inference versus explicit contracts, including client/server differences and distinguishing void from explicit null.
- Which conditional insert/upsert semantics are exposed as shared primitives and how their server conflict behavior is guaranteed.
- Protocol compatibility and retention policy for pending offline mutations during deployment.

These questions do not require more product discussion before recording the architecture. Public choices that materially change authorization, data ownership, identity strategy, or compatibility must still be surfaced when implementation makes them concrete.

## 18. References

- [Companion query design](QUERY_DESIGN.md)
- [Zero mutators: returning data](https://zero.rocicorp.dev/docs/mutators#returning-data)
- [Zero mutators: notifications and async work](https://zero.rocicorp.dev/docs/mutators#notifications-and-async-work)
- [Pinned zbugs mutation example and notification preference helper](https://github.com/rocicorp/mono/blob/e91e55f05b7a2c631f77238e1dd6fadf1029a538/apps/zbugs/shared/mutators.ts)
- [Pinned zbugs authorization helpers](https://github.com/rocicorp/mono/blob/e91e55f05b7a2c631f77238e1dd6fadf1029a538/apps/zbugs/shared/auth.ts)
- [Upstream mutator definition and JSON return signature](https://github.com/rocicorp/mono/blob/main/packages/zql/src/mutate/mutator.ts)
- [Upstream client mutation result data](https://github.com/rocicorp/mono/blob/main/packages/zero-client/src/client/custom.ts)
- [Laravel 13 authorization and policy responses](https://github.com/laravel/docs/blob/13.x/authorization.md)
- [Laravel 13 route model binding](https://github.com/laravel/docs/blob/13.x/routing.md#route-model-binding)
- [Laravel 13 queued work and database transactions](https://github.com/laravel/docs/blob/13.x/queues.md#jobs-and-database-transactions)

The official documentation, pinned example/helpers, current upstream return signatures, and existing package mutation layers were inspected during the design discussion. Branch-based APIs can change; implementation must verify the chosen versions. Writing this document does not change application code, install dependencies, or establish that these target capabilities already work.
