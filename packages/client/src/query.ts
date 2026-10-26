import {
    createBuilder,
    defineQuery,
    defineQueries,
    type Schema,
    type Query,
    type QueryRequest,
    type ReadonlyJSONValue,
    type CustomQuery,
    type QueryDefinition,
} from '@rocicorp/zero';
import type {
    ClientContext,
    ContractMap,
    ModelContract,
    FilterExpression,
    QueryArgs,
    IncludeArgs,
    ModelRow,
    Filter,
    FilterName,
    FilterValue,
    IncludeName,
    RelatedRow,
    RelatedName,
    RelationLimit,
} from './contract';
import type { IncludeSelection } from './include';
import { includeSpec, type IncludedRow } from './include';
import { hydrate, type ModelInstance } from './record';
import { createMutators, type ClientDefaults, type NativeMutators } from './mutation';
import { columnValue, contextValue, fail, freeze, validateQueryArgs, filterComparison } from './validation';

type Table<S extends Schema> = keyof S['tables'] & string;
type RawRow = Record<string, unknown>;
type StoredValue<C extends ModelContract['columns'][string]> =
    | (C['enum'] extends readonly (string | number)[]
          ? C['enum'][number]
          : C['type'] extends 'string'
            ? string
            : C['type'] extends 'number'
              ? number
              : C['type'] extends 'boolean'
                ? boolean
                : ReadonlyJSONValue)
    | (C['nullable'] extends true ? null : never);
type WireRow<D extends ContractMap, N extends keyof D & string, Row> = {
    readonly [F in keyof Row]: F extends keyof D[N]['columns']
        ? StoredValue<D[N]['columns'][F]>
        : F extends keyof D[N]['relations'] & string
          ? D[N]['relations'][F]['cardinality'] extends 'many'
              ? Row[F] extends readonly (infer Child)[]
                  ? readonly WireRow<D, RelatedName<D, N, F>, Child>[]
                  : never
              : WireRow<D, RelatedName<D, N, F>, Exclude<Row[F], undefined>> | undefined
          : Row[F];
};
export type NativeQueries<S extends Schema, D extends ContractMap> = {
    [N in keyof D]: {
        [O in D[N]['queries'][number]]: CustomQuery<
            Table<S>,
            QueryArgs,
            QueryArgs,
            S,
            RawRow | undefined,
            ClientContext
        >;
    };
};

// Schema and method names are dynamic at this one internal seam. The generated
// model surface retains concrete names and result types; Zero validates the schema.
type DynamicQuery = Query<string, Schema, RawRow | undefined>;
type DynamicBuilder = Record<string, DynamicQuery>;

export function createQueries<S extends Schema, D extends ContractMap>(schema: S, defs: D): NativeQueries<S, D> {
    const builder = createBuilder(schema) as unknown as DynamicBuilder;
    const definitions: Record<
        string,
        Record<string, QueryDefinition<string, QueryArgs, QueryArgs, RawRow | undefined, ClientContext>>
    > = Object.create(null);
    for (const [name, model] of Object.entries(defs)) {
        const operations: (typeof definitions)[string] = Object.create(null);
        for (const operation of model.queries)
            operations[operation] = defineQuery(({ args, ctx }: { args: QueryArgs; ctx: ClientContext }) => {
                validateQueryArgs(defs, model, operation, args);
                let query = buildQuery(builder[model.table]!, defs, model, args, ctx);
                if (operation === 'one') query = query.where(model.key as never, args.id as never).one();
                else query = query.limit(args.limit ?? model.defaultLimit);
                return query;
            });
        definitions[name] = operations;
    }
    return defineQueries(definitions) as unknown as NativeQueries<S, D>;
}

function buildQuery(
    query: DynamicQuery,
    defs: ContractMap,
    model: ModelContract,
    args: QueryArgs | IncludeArgs,
    ctx: ClientContext,
    junction = false,
    depth = 0,
): DynamicQuery {
    if (depth > 8) fail('include', 'Includes exceed the maximum depth.');
    for (const rule of model.authorization) {
        const value = 'context' in rule.value ? contextValue(ctx, rule.value.context) : rule.value.literal;
        query = query.where(rule.field as never, rule.op as never, value as never);
    }
    for (const name of model.authorizationRelations ?? []) {
        const relation = model.relations[name];
        if (!relation) fail('authorization', 'Unknown authorization relation.');
        const target = defs[relation.model];
        if (!target) fail('authorization', 'Unknown authorization model.');
        query = query.whereExists(name, (related) =>
            buildQuery(related as DynamicQuery, defs, target, {}, ctx, false, depth + 1),
        );
    }
    const filters = 'filters' in args ? (args.filters ?? {}) : {};
    const trashed = filters.trashed ?? 'without';
    if (model.timestamps.deleted && trashed !== 'with')
        query = query.where(model.timestamps.deleted as never, trashed === 'only' ? 'IS NOT' : 'IS', null as never);
    for (const [name, value] of Object.entries(filters)) {
        const filter = model.filters[name]!;
        if (filter.kind === 'trashed') continue;
        if (filter.kind === 'belongsTo') {
            const target = defs[model.relations[filter.field]!.model]!;
            query = query.whereExists(filter.field, (related) =>
                buildQuery(related as DynamicQuery, defs, target, {}, ctx, false, depth + 1).where(
                    target.key as never,
                    value as never,
                ),
            );
        } else if (filter.kind === 'group' || filter.kind === 'callback') {
            query = query.where((eb) => {
                const expression = (node: FilterExpression): ReturnType<typeof eb.cmp> => {
                    if (node.type !== 'simple')
                        return node.type === 'or'
                            ? eb.or(...node.conditions.map(expression))
                            : eb.and(...node.conditions.map(expression));
                    const operand = node.right.type === 'filterValue' ? value : node.right.value;
                    return eb.cmp(
                        node.left.name as never,
                        (operand === null && node.op === '=' ? 'IS' : node.op) as never,
                        operand as never,
                    );
                };
                if (filter.kind === 'callback') return expression(filter.expression!);
                const members = filter.members!.map((member) => {
                    const [op, operand] = filterComparison(model, member, value, name);
                    return eb.cmp(member.field as never, op as never, operand as never);
                });
                return filter.operator === 'or' ? eb.or(...members) : eb.and(...members);
            });
        } else {
            const [operator, operand] = filterComparison(model, filter, value, name);
            query = query.where(filter.field as never, operator as never, operand as never);
        }
    }
    if (!junction) {
        const order = args.order ?? model.defaultOrder;
        for (const [field, direction] of order) query = query.orderBy(field, direction);
        if (!order.some(([field]) => field === model.key)) query = query.orderBy(model.key, 'asc');
    }
    if (args.limit !== undefined) query = query.limit(args.limit);
    for (const [name, spec] of Object.entries(args.include ?? {})) {
        const relation = model.relations[name]!;
        query = query.related(name, (related) =>
            buildQuery(
                related as DynamicQuery,
                defs,
                defs[relation.model]!,
                spec,
                ctx,
                relation.path.length > 1,
                depth + 1,
            ),
        );
    }
    return query;
}

/** Canonicalize object keys; ordered arrays (including sort precedence) stay ordered. */
export function selectionKey(value: unknown): string {
    return JSON.stringify(value, (_key, child: unknown) =>
        child && typeof child === 'object' && !Array.isArray(child)
            ? // oxlint-disable-next-line unicorn/no-array-sort -- Entries is a freshly owned array.
              Object.fromEntries(Object.entries(child).sort(([a], [b]) => a.localeCompare(b)))
            : child,
    );
}

export type SelectionData<Q> = Q extends { decode(raw: unknown): infer Data } ? Data : never;

export class Selection<
    S extends Schema,
    D extends ContractMap,
    N extends keyof D & string,
    Row = ModelRow<D, N>,
    One extends boolean = false,
> {
    constructor(
        readonly schema: S,
        readonly definitions: D,
        readonly name: N,
        readonly queries: NativeQueries<S, D>,
        readonly one: One,
        readonly args: Readonly<QueryArgs> = {},
        readonly pageSize?: number,
        readonly defaults: ClientDefaults = {},
        readonly mutators: NativeMutators<S, D> = createMutators<S, D>(schema, definitions),
    ) {
        freeze(this.args);
    }

    get contract(): D[N] {
        return this.definitions[this.name]!;
    }
    get key(): string {
        return selectionKey([this.name, this.one, this.args, this.pageSize]);
    }

    private copy<NextRow = Row>(args: QueryArgs, pageSize = this.pageSize): Selection<S, D, N, NextRow, One> {
        validateQueryArgs(this.definitions, this.contract, this.one ? 'one' : 'all', args);
        return new Selection(
            this.schema,
            this.definitions,
            this.name,
            this.queries,
            this.one,
            args,
            pageSize,
            this.defaults,
            this.mutators,
        );
    }

    filter<F extends FilterName<D, N>>(this: Selection<S, D, N, Row>, filter: Filter<D, N, F>): Selection<S, D, N, Row>;
    filter<F extends FilterName<D, N>>(
        this: Selection<S, D, N, Row>,
        name: F,
        value: FilterValue<D, N, F> | undefined,
    ): Selection<S, D, N, Row>;
    filter(
        this: Selection<S, D, N, Row>,
        input: Filter<D, N> | FilterName<D, N>,
        value?: unknown,
    ): Selection<S, D, N, Row> {
        const filter = typeof input === 'string' ? { model: this.name, name: input, value } : input;
        if (filter.model !== this.name) fail('filter', 'Filter belongs to another model.');
        const definition = this.contract.filters[filter.name];
        if (!definition) fail('filter', 'Unknown filter.');
        const filters = { ...this.args.filters };
        if (filter.value === undefined) delete filters[filter.name];
        else {
            const column = this.contract.columns[definition.field];
            filters[filter.name] =
                definition.operator === 'dynamic' && column
                    ? {
                          operator: (filter.value as { operator: string }).operator,
                          value: columnValue((filter.value as { value: unknown }).value, column, filter.name, true),
                      }
                    : column
                      ? columnValue(filter.value, column, filter.name, true)
                      : (filter.value as ReadonlyJSONValue);
        }
        return this.copy({ ...this.args, filters });
    }

    filters(this: Selection<S, D, N, Row>, filters: readonly Filter<D, N>[]): Selection<S, D, N, Row> {
        return filters.reduce((query, filter) => query.filter(filter), this);
    }

    with<R extends IncludeName<D, N>>(
        relation: R,
        limit?: RelationLimit<D, N, R>,
    ): Selection<S, D, N, Omit<Row, R> & { readonly [K in R]: RelatedRow<D, N, R> }, One>;
    with<R extends IncludeName<D, N>, Child>(
        relation: R,
        refine: (
            child: IncludeSelection<D, RelatedName<D, N, R>, ModelRow<D, RelatedName<D, N, R>>, RelationLimit<D, N, R>>,
        ) => IncludeSelection<D, RelatedName<D, N, R>, Child, RelationLimit<D, N, R>>,
    ): Selection<S, D, N, Omit<Row, R> & { readonly [K in R]: IncludedRow<D, N, R, Child> }, One>;
    with(relation: IncludeName<D, N>, refinement?: unknown): Selection<S, D, N, any, One> {
        const spec = includeSpec(this.definitions, this.name, relation, refinement);
        return this.copy({
            ...this.args,
            include: { ...this.args.include, [relation]: spec },
        });
    }

    orderBy(
        this: Selection<S, D, N, Row>,
        field: D[N]['sorts'][number],
        direction: 'asc' | 'desc' = 'asc',
    ): Selection<S, D, N, Row> {
        return this.copy({
            ...this.args,
            order: [...(this.args.order ?? []).filter(([key]) => key !== field), [field, direction]],
        });
    }

    limit(this: Selection<S, D, N, Row>, limit: number): Selection<S, D, N, Row> {
        return this.copy({ ...this.args, limit });
    }

    /** Replace ordering, including returning to the model default with undefined. */
    reorder(
        this: Selection<S, D, N, Row>,
        order: readonly (readonly [D[N]['sorts'][number], 'asc' | 'desc'])[] | undefined,
    ): Selection<S, D, N, Row> {
        const { order: _order, ...args } = this.args;
        return this.copy(
            order === undefined ? args : { ...args, order: order.map(([field, direction]) => [field, direction]) },
        );
    }
    withTrashed(
        this: Selection<S, D, N, Row> & ('trashed' extends FilterName<D, N> ? unknown : never),
    ): Selection<S, D, N, Row> {
        return this.copy({
            ...this.args,
            filters: { ...this.args.filters, trashed: 'with' },
        });
    }
    onlyTrashed(
        this: Selection<S, D, N, Row> & ('trashed' extends FilterName<D, N> ? unknown : never),
    ): Selection<S, D, N, Row> {
        return this.copy({
            ...this.args,
            filters: { ...this.args.filters, trashed: 'only' },
        });
    }
    infinite(this: Selection<S, D, N, Row>, pageSize = this.contract.defaultLimit): Selection<S, D, N, Row> {
        if (!Number.isInteger(pageSize) || pageSize < 1) fail('limit', 'Page size must be a positive integer.');
        return this.copy({ ...this.args, limit: Math.min(pageSize, this.contract.maxLimit) }, pageSize);
    }

    request(): QueryRequest<
        Table<S>,
        QueryArgs,
        QueryArgs,
        S,
        One extends true ? WireRow<D, N, Row> | undefined : WireRow<D, N, Row>,
        ClientContext
    > {
        const operation = this.one ? 'one' : 'all';
        const query = (
            this.queries[this.name] as Partial<
                Record<'all' | 'one', CustomQuery<Table<S>, QueryArgs, QueryArgs, S, RawRow | undefined, ClientContext>>
            >
        )[operation];
        if (!query) fail('query', 'Query is not enabled.');
        return query(this.args) as unknown as QueryRequest<
            Table<S>,
            QueryArgs,
            QueryArgs,
            S,
            One extends true ? WireRow<D, N, Row> | undefined : WireRow<D, N, Row>,
            ClientContext
        >;
    }

    decode(
        raw: unknown,
    ): One extends true ? ModelInstance<S, D, N, Row> | undefined : readonly ModelInstance<S, D, N, Row>[] {
        const decodeRow = (row: unknown) =>
            hydrate(this.schema, this.definitions, this.name, this.queries, this.mutators, this.defaults, row);
        return (this.one ? decodeRow(raw) : Array.isArray(raw) ? raw.map(decodeRow) : []) as One extends true
            ? ModelInstance<S, D, N, Row> | undefined
            : readonly ModelInstance<S, D, N, Row>[];
    }
}
