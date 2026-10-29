import {
    defineMutator,
    createBuilder,
    defineMutators,
    type Schema,
    type MutateRequest,
    type ReadonlyJSONValue,
    type Transaction,
    type Mutator,
    type MutatorDefinition,
    type AnyMutatorRegistry,
} from '@rocicorp/zero';
import { mutationForm, mutationFormInput, type MutationDefinition, type MutationDraft } from './form';
import { completeMutation, operationForm, type MutationClient, type MutationFormOptions } from './operation';
import { ulid } from 'ulid';
import { v7 as uuidv7 } from 'uuid';
import type {
    ClientContext,
    ContractMap,
    ModelContract,
    MutationInput,
    Operation,
    RecordId,
    RelatedName,
    Relation,
} from './contract';
import {
    columnValue,
    contextValue,
    fail,
    freeze,
    keys,
    object,
    validateRules,
    decodeColumn,
    InputError,
} from './validation';

export type MutationArgs<ID extends string | number = string | number> = {
    id: ID;
    data: { [key: string]: ReadonlyJSONValue };
    now: number;
    relations?: { [name: string]: { operation: 'create' | 'update'; records: MutationArgs[] } };
};
export type ClientDefaults = {
    now?: () => Date;
    id?: (kind: 'uuid' | 'ulid', model: ModelContract) => string;
};
export type OperationInput<
    D extends ContractMap,
    N extends keyof D,
    O extends Operation,
    Bound extends boolean,
> = Bound extends true ? Omit<MutationInput<D, N, O>, 'id'> & { readonly id?: never } : MutationInput<D, N, O>;
export type NativeMutators<S extends Schema, D extends ContractMap> = AnyMutatorRegistry & {
    [N in keyof D]: {
        [O in D[N]['mutations'][number]]: Mutator<MutationArgs<RecordId<D, N>>, S, ClientContext>;
    };
};

type TableWriter = {
    insert(row: Record<string, ReadonlyJSONValue>): Promise<void>;
    update(row: Record<string, ReadonlyJSONValue>): Promise<void>;
    delete(row: Record<string, ReadonlyJSONValue>): Promise<void>;
};

export function createMutators<S extends Schema, D extends ContractMap>(schema: S, defs: D): NativeMutators<S, D> {
    const zql = createBuilder(schema as Schema);
    const definitions: Record<
        string,
        Record<string, MutatorDefinition<MutationArgs, MutationArgs, ClientContext>>
    > = Object.create(null);
    for (const [name, rootModel] of Object.entries(defs)) {
        const operations: (typeof definitions)[string] = Object.create(null);
        for (const rootOperation of rootModel.mutations)
            operations[rootOperation] = defineMutator(
                async ({ tx, args: rootArgs, ctx }: { tx: Transaction<S>; args: MutationArgs; ctx: ClientContext }) => {
                    const nodes = mutationNodes(defs, rootModel, rootOperation, rootArgs);
                    for (const node of nodes) {
                        const { model, operation, args, bindings } = node;
                        // Queries and writes use the same native Zero transaction. Missing cached
                        // rows cannot establish membership; Laravel always verifies it authoritatively.
                        const current =
                            operation === 'update' && Object.keys(bindings).length > 0
                                ? ((await tx.run(zql[model.table]!.where(model.key, args.id).one() as never)) as
                                      | Record<string, unknown>
                                      | undefined)
                                : undefined;
                        if (current) {
                            for (const [field, value] of Object.entries(bindings)) {
                                const creating = node.creatingBelongsTo.has(field);
                                if (current[field] !== value && !(creating && current[field] === null))
                                    fail(field, 'The record does not belong to this relationship.');
                            }
                        }
                        const table = (tx.mutate as unknown as Record<string, TableWriter>)[model.table]!;
                        const row: Record<string, ReadonlyJSONValue> = { [model.key]: args.id, ...args.data };
                        if (operation === 'create') {
                            for (const [field, column] of Object.entries(model.columns)) {
                                if (field in row) continue;
                                if (column.hasDefault) row[field] = column.default;
                                else if (column.nullable) row[field] = null;
                            }
                            for (const [field, source] of Object.entries(model.serverFill))
                                row[field] = columnValue(contextValue(ctx, source), model.columns[field]!, field);
                            if (model.timestamps.created) row[model.timestamps.created] = args.now;
                            if (model.timestamps.updated) row[model.timestamps.updated] = args.now;
                            if (model.timestamps.deleted) row[model.timestamps.deleted] = null;
                            await table.insert(row);
                        } else if (
                            operation === 'force_delete' ||
                            (operation === 'destroy' && !model.timestamps.deleted)
                        )
                            await table.delete({ [model.key]: args.id });
                        else {
                            if (model.timestamps.updated) row[model.timestamps.updated] = args.now;
                            if (operation === 'destroy') row[model.timestamps.deleted!] = args.now;
                            if (operation === 'restore') row[model.timestamps.deleted!] = null;
                            await table.update(row);
                        }
                    }
                },
            );
        definitions[name] = operations;
    }
    return defineMutators(definitions) as unknown as NativeMutators<S, D>;
}

type MutationNode = {
    model: ModelContract;
    operation: Operation;
    args: MutationArgs;
    bindings: Record<string, ReadonlyJSONValue>;
    creatingBelongsTo: Set<string>;
};

function prefixed<T>(path: string, callback: () => T): T {
    try {
        return callback();
    } catch (error) {
        if (!(error instanceof InputError)) throw error;
        throw new InputError(
            Object.fromEntries(
                Object.entries(error.fields).map(([field, message]) => [
                    path ? `${path}.${field.replace(/^(?:args\.)?data\./, '')}` : field,
                    message,
                ]),
            ),
        );
    }
}

function mutationNodes(
    defs: ContractMap,
    rootModel: ModelContract,
    rootOperation: Operation,
    rootArgs: MutationArgs,
): MutationNode[] {
    const seen = new Set<string>();
    const visit = (
        model: ModelContract,
        operation: Operation,
        value: unknown,
        bindings: Record<string, ReadonlyJSONValue>,
        depth: number,
        path: string,
    ): MutationNode[] =>
        prefixed(path, () => {
            if (depth > 8 || seen.size >= 100)
                fail('relations', 'Nested mutations exceed the maximum depth or record count.');
            const args = object(value, 'args') as MutationArgs;
            keys(args, ['id', 'data', 'now', 'relations'], 'args');
            columnValue(args.id, model.columns[model.key]!, 'id');
            const identity = `${model.table}:${args.id}`;
            if (seen.has(identity)) fail('id', 'A record may only occur once in a nested mutation.');
            seen.add(identity);
            const relations = object(args.relations === undefined ? {} : args.relations, 'relations');
            const allowed =
                operation === 'create' || operation === 'update' ? (model.nestedMutations?.[operation] ?? {}) : {};
            keys(relations, Object.keys(allowed), 'relations');
            const before: MutationNode[] = [],
                after: MutationNode[] = [];
            const creatingBelongsTo = new Set<string>();
            const children: { name: string; relation: Relation; operation: 'create' | 'update'; records: unknown[] }[] =
                [];
            for (const [name, relationInput] of Object.entries(relations)) {
                const spec = object(relationInput, name);
                keys(spec, ['operation', 'records'], name);
                if (
                    (spec.operation !== 'create' && spec.operation !== 'update') ||
                    !allowed[name]?.includes(spec.operation)
                )
                    fail(name, 'Nested operation is not allowed.');
                const relation = model.relations[name]!;
                if (!relation.kind || relation.path.length !== 1) fail(name, 'Unsupported nested relationship.');
                if (
                    !Array.isArray(spec.records) ||
                    spec.records.length > 100 ||
                    (relation.cardinality === 'one' && spec.records.length !== 1)
                )
                    fail(name, 'Invalid nested record count.');
                children.push({ name, relation, operation: spec.operation, records: spec.records });
                if (relation.kind === 'belongsTo') {
                    const field = relation.path[0]!.source[0]!;
                    if (field in bindings) fail(name, 'Conflicting relationship bindings.');
                    bindings[field] = object(spec.records[0], name).id as ReadonlyJSONValue;
                    if (spec.operation === 'create') creatingBelongsTo.add(field);
                }
            }
            keys(
                object(args.data, 'data'),
                model.writable.filter((field) => !(field in bindings)),
                'data',
            );
            const injected =
                operation === 'create'
                    ? bindings
                    : Object.fromEntries(Object.entries(bindings).filter(([field]) => creatingBelongsTo.has(field)));
            const effective = { ...args, data: { ...args.data, ...injected } };
            validateMutation(model, operation, effective, Object.keys(injected));
            for (const child of children) {
                const belongsTo = child.relation.kind === 'belongsTo';
                for (const [index, record] of child.records.entries()) {
                    const childPath = child.relation.cardinality === 'many' ? `${child.name}.${index}` : child.name;
                    const nodes = visit(
                        defs[child.relation.model]!,
                        child.operation,
                        record,
                        belongsTo ? {} : { [child.relation.path[0]!.dest[0]!]: args.id },
                        depth + 1,
                        childPath,
                    );
                    (belongsTo ? before : after).push(...nodes);
                }
            }
            return [...before, { model, operation, args: effective, bindings, creatingBelongsTo }, ...after];
        });
    return visit(rootModel, rootOperation, rootArgs, {}, 0, '');
}

function validateMutation(
    model: ModelContract,
    operation: Operation,
    args: MutationArgs,
    bindings: string[] = [],
): void {
    keys(object(args, 'args'), ['id', 'data', 'now', 'relations'], 'args');
    columnValue(args.id, model.columns[model.key]!, 'id');
    if (args.id === '') fail('id', 'Expected a record id.');
    if (!Number.isInteger(args.now) || Math.abs(args.now) > 8640000000000000) fail('now', 'Invalid timestamp.');
    const data = object(args.data, 'data');
    keys(data, operation === 'create' || operation === 'update' ? [...model.writable, ...bindings] : [], 'data');
    for (const [field, value] of Object.entries(data)) columnValue(value, model.columns[field]!, field);
    if (operation === 'create')
        for (const field of model.writable) {
            const column = model.columns[field]!;
            if (!(field in data) && !column.nullable && !column.hasDefault) fail(field, 'This field is required.');
        }
    const publicData = Object.fromEntries(
        Object.entries(data).map(([field, value]) => [field, decodeColumn(value, model.columns[field]!, field)]),
    );
    validateRules(model, operation, { ...publicData, id: args.id });
}

type NestedName<D extends ContractMap, N extends keyof D, O extends Operation> = D[N] extends {
    readonly nestedMutations: infer M;
}
    ? O extends keyof M
        ? keyof M[O] & keyof D[N]['relations'] & string
        : never
    : never;
type NestedOperation<
    D extends ContractMap,
    N extends keyof D,
    O extends Operation,
    R extends NestedName<D, N, O>,
> = D[N] extends { readonly nestedMutations: infer M }
    ? O extends keyof M
        ? R extends keyof M[O]
            ? M[O][R] extends readonly (infer C)[]
                ? C & ('create' | 'update')
                : never
            : never
        : never
    : never;
type ChildInput<
    D extends ContractMap,
    N extends keyof D,
    R extends keyof D[N]['relations'] & string,
    C extends Operation,
> = Omit<
    MutationInput<D, RelatedName<D, N, R>, C>,
    D[N]['relations'][R] extends {
        readonly kind: 'hasOne' | 'hasMany';
        readonly path: readonly [{ readonly dest: readonly [infer F] }];
    }
        ? F & string
        : never
>;
type ParentForeignKey<
    D extends ContractMap,
    N extends keyof D,
    R extends keyof D[N]['relations'] & string,
> = D[N]['relations'][R] extends {
    readonly kind: 'belongsTo';
    readonly path: readonly [{ readonly source: readonly [infer F] }];
}
    ? F & string
    : never;
type NestedValue<
    D extends ContractMap,
    N extends keyof D,
    R extends keyof D[N]['relations'] & string,
    Child,
> = D[N]['relations'][R]['cardinality'] extends 'many' ? readonly Child[] : Child;
type WithInput<
    D extends ContractMap,
    N extends keyof D,
    R extends keyof D[N]['relations'] & string,
    Input,
    Child,
> = Omit<Input, R | ParentForeignKey<D, N, R>> &
    ([ParentForeignKey<D, N, R>] extends [never]
        ? { [K in R]?: NestedValue<D, N, R, Child> }
        : ParentForeignKey<D, N, R> extends keyof Input
          ? {} extends Pick<Input, ParentForeignKey<D, N, R>>
              ? { [K in R]?: NestedValue<D, N, R, Child> }
              : { [K in R]: NestedValue<D, N, R, Child> }
          : { [K in R]?: NestedValue<D, N, R, Child> });
export type NestedSelections = Readonly<
    Record<string, { readonly operation: 'create' | 'update'; readonly relations: NestedSelections }>
>;

function parentForeignKeys(model: ModelContract, nested: NestedSelections): readonly string[] {
    return Object.keys(nested).flatMap((name) => {
        const relation = model.relations[name];
        return relation?.kind === 'belongsTo' ? relation.path[0]!.source : [];
    });
}

export class Mutation<
    S extends Schema,
    D extends ContractMap,
    N extends keyof D & string,
    O extends Operation,
    Bound extends boolean = false,
    Input = OperationInput<D, N, O, Bound>,
> {
    constructor(
        readonly definitions: D,
        readonly name: N,
        readonly operation: O,
        readonly mutators: NativeMutators<S, D>,
        readonly defaults: ClientDefaults,
        readonly record?: Readonly<Record<string, unknown>>,
        readonly nested: NestedSelections = {},
    ) {}
    get defaultValues(): Partial<Input> {
        if (!this.record || this.operation !== 'update') return {};
        const visit = (
            model: ModelContract,
            nested: NestedSelections,
            record: Readonly<Record<string, unknown>>,
            excluded: readonly string[] = [],
            includeId = false,
            depth = 0,
        ): Record<string, unknown> => {
            if (depth > 8) fail('relations', 'Nested mutations exceed the maximum depth.');
            const ownBindings = Object.keys(nested).flatMap((name) =>
                model.relations[name]?.kind === 'belongsTo' ? [...model.relations[name].path[0]!.source] : [],
            );
            const values: Record<string, unknown> = Object.fromEntries(
                model.writable
                    .filter((field) => field in record && !excluded.includes(field) && !ownBindings.includes(field))
                    .map((field) => [field, record[field]]),
            );
            if (includeId) values.id = record[model.key];
            for (const [name, spec] of Object.entries(nested)) {
                if (spec.operation !== 'update' || !(name in record)) continue;
                const relation = model.relations[name]!;
                const decode = (value: unknown) => {
                    if (!value || typeof value !== 'object' || !('data' in value)) return undefined;
                    return visit(
                        this.definitions[relation.model]!,
                        spec.relations,
                        value.data as Readonly<Record<string, unknown>>,
                        relation.kind === 'belongsTo' ? [] : relation.path[0]!.dest,
                        true,
                        depth + 1,
                    );
                };
                const value = record[name];
                const decoded = Array.isArray(value)
                    ? value.map(decode).filter((item) => item !== undefined)
                    : decode(value);
                if (decoded !== undefined) values[name] = decoded;
            }
            return values;
        };
        return structuredClone(visit(this.contract, this.nested, this.record)) as Partial<Input>;
    }
    get contract(): D[N] {
        return this.definitions[this.name]!;
    }
    with<R extends NestedName<D, N, O>>(
        name: R & (O extends NestedOperation<D, N, O, R> ? unknown : never),
    ): Mutation<S, D, N, O, Bound, WithInput<D, N, R, Input, ChildInput<D, N, R, O>>>;
    with<R extends NestedName<D, N, O>, C extends NestedOperation<D, N, O, R>>(
        name: R,
        operation: C,
    ): Mutation<S, D, N, O, Bound, WithInput<D, N, R, Input, ChildInput<D, N, R, C>>>;
    with<R extends NestedName<D, N, O>, C extends NestedOperation<D, N, O, R>, Child>(
        name: R,
        operation: C,
        refine: (
            child: Mutation<S, D, RelatedName<D, N, R> & string, C, false, ChildInput<D, N, R, C>>,
        ) => Mutation<S, D, RelatedName<D, N, R> & string, C, false, Child>,
    ): Mutation<S, D, N, O, Bound, WithInput<D, N, R, Input, Child>>;
    with(
        name: string,
        operation: 'create' | 'update' = this.operation as 'create' | 'update',
        refine?: (child: never) => {
            readonly name: string;
            readonly operation: Operation;
            readonly nested: NestedSelections;
        },
    ): unknown {
        const allowed =
            this.operation === 'create' || this.operation === 'update'
                ? this.contract.nestedMutations?.[this.operation]?.[name]
                : undefined;
        if (!allowed?.includes(operation)) fail(name, 'Nested operation is not allowed.');
        const relation = this.contract.relations[name]!;
        const child = new Mutation(this.definitions, relation.model, operation, this.mutators, this.defaults);
        const selected = refine ? refine(child as never) : child;
        if (selected.name !== child.name || selected.operation !== child.operation)
            fail(name, 'A nested refinement cannot change its model or operation.');
        return new Mutation(this.definitions, this.name, this.operation, this.mutators, this.defaults, this.record, {
            ...this.nested,
            [name]: { operation, relations: selected.nested },
        });
    }
    get form() {
        const form = mutationForm(
            this.definitions,
            this.contract,
            this.operation,
            this.nested,
            this.record !== undefined,
            this.defaultValues,
        );
        return { ...form, definition: form.definition as MutationDefinition<D, N, Input> };
    }

    /** Preserve patch semantics at every level of a bound update form. */
    formValues(formValues: Partial<Input>, defaultValues: Partial<Input>): Partial<Input> {
        if (!this.record || this.operation !== 'update') return formValues;
        const patch = (
            model: ModelContract,
            nested: NestedSelections,
            values: Record<string, unknown>,
            defaults: Record<string, unknown>,
        ): Record<string, unknown> => {
            const rules = model.validation.update ?? {};
            const result: Record<string, unknown> = {};
            for (const [field, value] of Object.entries(values)) {
                const spec = nested[field];
                if (spec?.operation === 'update') {
                    const relation = model.relations[field]!;
                    const target = this.definitions[relation.model]!;
                    const childPatch = (childValue: unknown, original: unknown) => {
                        const child = object(childValue, field);
                        const previous =
                            original && typeof original === 'object' ? (original as Record<string, unknown>) : {};
                        const changed = patch(target, spec.relations, child, previous);
                        return Object.keys(changed).some((key) => key !== 'id') ? changed : undefined;
                    };
                    if (relation.cardinality === 'many' && Array.isArray(value)) {
                        const previous = Array.isArray(defaults[field])
                            ? (defaults[field] as Record<string, unknown>[])
                            : [];
                        const children = value
                            .map((item) =>
                                childPatch(
                                    item,
                                    previous.find((old) => old.id === item.id),
                                ),
                            )
                            .filter((item) => item !== undefined);
                        if (children.length) result[field] = children;
                    } else {
                        const child = childPatch(value, defaults[field]);
                        if (child) result[field] = child;
                    }
                } else if (
                    field === 'id' ||
                    (rules[field]?.includes('required') && !rules[field]?.includes('sometimes')) ||
                    JSON.stringify(value) !== JSON.stringify(defaults[field])
                )
                    result[field] = value;
            }
            return result;
        };
        return patch(this.contract, this.nested, formValues, defaultValues) as Partial<Input>;
    }

    async execute(
        client: MutationClient<S>,
        input: Input,
        options: { signal?: AbortSignal } = {},
    ): Promise<{ id: RecordId<D, N> }> {
        options.signal?.throwIfAborted();
        const request = this.prepare(input);
        await completeMutation(client.mutate(request), options.signal);
        return { id: request.args.id };
    }

    createForm(client: MutationClient<S>, options: MutationFormOptions<MutationDraft<Input> & object> = {}) {
        const generated = this.form.definition;
        return operationForm(
            generated,
            async (values, signal) => {
                const patch = this.formValues(values, generated.defaultValues);
                const input = mutationFormInput(this.definitions, this.contract, this.nested, patch);
                return this.execute(client, input as Input, { signal });
            },
            options,
        );
    }

    prepare(formInput: Input): MutateRequest<MutationArgs<RecordId<D, N>>, S, ClientContext> {
        const now = (this.defaults.now ?? (() => new Date()))().getTime();
        let count = 0;
        const prepare = (
            model: ModelContract,
            operation: Operation,
            nested: NestedSelections,
            input: unknown,
            record?: Readonly<Record<string, unknown>>,
            depth = 0,
            path = '',
        ): MutationArgs =>
            prefixed(path, () => {
                if (depth > 8 || ++count > 100)
                    fail('relations', 'Nested mutations exceed the maximum depth or record count.');
                const value = object(input, 'input');
                keys(
                    value,
                    [
                        ...(operation === 'create' || operation === 'update'
                            ? model.writable.filter((field) => !parentForeignKeys(model, nested).includes(field))
                            : []),
                        ...Object.keys(nested),
                        ...(record ? [] : ['id']),
                    ],
                    'input',
                );
                const data: Record<string, ReadonlyJSONValue> = {};
                for (const [field, item] of Object.entries(value))
                    if (field !== 'id' && !(field in nested))
                        data[field] = columnValue(item, model.columns[field]!, field, true);
                const id =
                    record?.[model.key] ??
                    value.id ??
                    (operation === 'create' && model.idGenerator
                        ? (this.defaults.id ?? ((kind) => (kind === 'uuid' ? uuidv7() : ulid())))(
                              model.idGenerator,
                              model,
                          )
                        : undefined);
                const args: MutationArgs = { id: id as string | number, data, now };
                for (const [name, spec] of Object.entries(nested)) {
                    if (!(name in value)) continue;
                    const relation = model.relations[name]!;
                    const values = relation.cardinality === 'many' ? value[name] : [value[name]];
                    if (!Array.isArray(values) || values.length > 100) fail(name, 'Invalid nested record count.');
                    const records = values.map((item, index) =>
                        prepare(
                            this.definitions[relation.model]!,
                            spec.operation,
                            spec.relations,
                            item,
                            undefined,
                            depth + 1,
                            relation.cardinality === 'many' ? `${name}.${index}` : name,
                        ),
                    );
                    (args.relations ??= {})[name] = { operation: spec.operation, records };
                }
                return args;
            });
        const args = prepare(this.contract, this.operation, this.nested, formInput, this.record) as MutationArgs<
            RecordId<D, N>
        >;
        mutationNodes(this.definitions, this.contract, this.operation, args);
        const mutator = (
            this.mutators[this.name] as Partial<
                Record<Operation, Mutator<MutationArgs<RecordId<D, N>>, S, ClientContext>>
            >
        )[this.operation];
        if (!mutator) fail('mutation', 'Mutation is not enabled.');
        return mutator(freeze(args));
    }
}
