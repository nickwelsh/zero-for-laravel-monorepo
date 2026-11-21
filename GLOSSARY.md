# Zero for Laravel

Laravel applications declare what people can read and change. Zero keeps those reads current and lets people see local changes while Laravel decides their outcome.

## Language

### Models and replication

**Participating model**\
An application model opted into Zero. Its exposed columns and relationships describe the records a live query can return.

_Avoid_ “registered model” when describing the application's choice to opt in.

**Publication**\
The set of tables and columns PostgreSQL makes available to zero-cache for replication. It bounds what the cache can receive across all users.

**Public model**\
A participating model explicitly open for reading without a per-record policy check. A query on a public model can still restrict which records it returns.

**Protected model**\
A participating model whose records require policy visibility or a qualifying access check wherever they are read, including through relationships.

**Zero identity**\
The user identity to which a Zero client's local data belongs. Laravel authenticates the user when serving their operations.

**Application context**\
Named values supplied by the application for the current request, such as the current organization. Laravel resolves the trusted values even when the client has corresponding values for local use.

**Operation contract**\
The shared definition of a query or mutation that Laravel and the client agree to execute. Its identity distinguishes one version of the operation from another, even when the application keeps the same name.

### Queries and pages

**Query declaration**\
An application's description of which records to select, which related records to include, and how to order or limit the result.

**Live query**\
A read whose result Zero keeps current as matching records or their visibility change. A record can enter or leave the result while the query stays active.

**Live prop**\
An Inertia page prop whose value comes from a live query. On a lists page, for example, the heading can come from the page response while the lists continue to update.

**Live prop binding**\
The association between a page prop and a particular query with its arguments and any required access check. The same page component can receive different bindings on different visits.

**Controller query**\
A query declared by a routed controller action for a live prop. Two actions can render the same page component and supply different queries for the same prop.

**Standalone query**\
A named live query the application can use independently of a page or route. It can have validated inputs and reads under the current user's identity.

**Query transform**\
Laravel's resolution of a registered query and its arguments into the read Zero may serve for the current user. For a page query, this includes the relevant route checks without rendering the page again.

**Zero scope**\
A reusable part of a model's query declaration that can constrain reads or supply creation defaults. A local scope applies where requested and a global scope applies by default.

**Creation default**\
A value supplied for an omitted attribute when creating a record through Zero. Explicit creation values take precedence, and defaults apply only at creation.

**Editable query input**\
A validated argument the user can change to refine a live query, such as the status filter on a list of tasks.

**Live window**\
The leading portion of an ordered live result, up to its current limit. Loading more grows that portion, so a newly matching record near the top can displace one at the end.

**Cursor page**\
A bounded portion of a live result positioned by ordering values from a record. Returning to an earlier cursor shows the records that currently fall there.

**Query preload**\
Starting a destination's eligible live queries in anticipation of a visit. Inertia's page prefetch independently fetches the page response.

_Avoid_ “page prefetch” for warming only the Zero queries.

### Permissions and access

**Actor**\
The user whose access Laravel is evaluating. The same person can have different permissions in different domains.

**Ability**\
The operation a Laravel policy evaluates for an actor, such as viewing a list or creating one. The policy can map the ability to a named action in a permission rule.

**Permission domain**\
The boundary within which a scoped permission rule or role assignment applies. A user can be an owner in one list's domain and a reader in another.

**Managed grant**\
A permission rule recorded by the application that allows or denies an action to a user or role under specified conditions. A role assignment makes a role's rules applicable to its members.

**Permission projection**\
Private facts derived from permission rules, roles, and application records so changes in any of them can keep access decisions current.

_Avoid_ “projection” alone when it could also mean a mutation's local prediction.

**Policy visibility**\
The set of records an actor may read under the model's policy. It applies to records returned directly, related records included with them, and related records whose existence affects the result.

**Class permission**\
An ability evaluated for a model as a whole, such as opening its index or creating a record. An actor can have permission to open the index and still see an empty collection.

**Instance permission**\
An ability evaluated for one identified record. An actor who can view a list can still lack permission to rename it.

**Access watch**\
A live, authoritative check for the current actor and context. A guarded page uses it to learn whether access still holds even when its data query has no rows.

**Guarded page**\
A page whose continued display depends on a policy ability captured from the page action, such as viewing a particular list or opening an index. Losing one row from an otherwise authorized index leaves the page accessible.

**Revocation**\
Confirmed loss of an access permission the actor previously held. A disconnect leaves access pending until it can be checked again.

**Permission prediction**\
A client-side estimate of whether an actor can perform an ability, based on permission facts the application has chosen to share. Laravel makes the authoritative decision.

**Live permission check**\
An authoritative answer, kept current for the UI, about one class or instance permission. A share button can follow this check while the share mutation still requires its own authorization.

### Mutations

**Controller mutation**\
A Laravel controller action explicitly offered through Zero as a write. One submission can have a local effect before Laravel accepts or rejects it.

**Business input**\
The values the caller supplies to a mutation to describe the requested change, such as a task title.

**Prepared values**\
Values chosen once for a submission, such as a new record's identifier and timestamp. The same values accompany local execution, replay, and authoritative execution.

**Optimistic prediction**\
The local effect or result the client expects from a mutation. Generated optimism derives it from the declared action, custom optimism uses an application-supplied prediction, and disabled optimism queues the write without predicting changes.

_Avoid_ “saved” or “accepted” for a change that has only been applied locally.

**Replay**\
Zero's reapplication of a pending mutation's prediction against newer local data. The original submission and its prepared values stay the same even if the predicted result changes.

**Server-only operation**\
Work within a mutation reserved for authoritative execution. Granting the creator access to a newly created list is one example.

**Shared operation**\
A step in a mutation whose meaning is shared by local prediction and authoritative execution. For example, creating a preference only if absent preserves an existing opt-out in either place.

**Mutation outcome**\
Laravel's recorded acceptance or rejection of a submission, including any result data or application error. If a response is lost after a write commits, the same outcome can be recovered without performing the write again.

**Navigation instruction**\
A redirecting mutation's direction to stay on the current page or visit a named destination. A predictable visit can follow local application while server acceptance is still pending.

### Tables

**Table definition**\
The application's declaration of the records a table represents and the ways a viewer may filter, sort, or group them. Two views of the same definition can show different subsets of those records.

**Table state**\
One table view's filter, row ordering, ordered grouping rules, and expanded groups. Sharing this state in a URL lets another actor open the same view settings over the records they may see.

**Group path**\
The ordered field-and-value pairs identifying a group within a table. When tasks are grouped by completion and then by list, the same list appears under a distinct path for each completion value.

**Group directory**\
The immediate child groups at a given path, with their labels and counts. Group ordering determines the order of these children independently of row ordering.

**Table metadata snapshot**\
The authorized total and any requested group directories as observed at a point in time. A live row can move to another group before a refresh reflects the move in the counts.

**Leaf**\
A group with no further grouping levels, whose items are live rows in their own window. An ungrouped table has one leaf at the root.

**Cell draft**\
An unsubmitted edit belonging to a particular row and column. The draft can stay with that cell even if the row moves to another group.

### Forms and search

**Option source**\
A form field's source of selectable values and labels. Its listing supplies eligible choices, and an optional selection lookup can resolve the current choice even when it falls outside the listing.

**Option context**\
Named arguments of the form's mutation used as inputs to an option source, such as the list being edited. Laravel checks access to a referenced model before serving its options.

_Avoid_ “application context” for values supplied by the form.

**Search hit**\
A ranked record identifier returned by the search index under its own access filter. An authorized live query supplies the record displayed for that hit.
