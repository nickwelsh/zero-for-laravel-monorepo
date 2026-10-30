import {
    createBuilder,
    defineMutator,
    defineMutators,
    type Schema,
    type Transaction,
    type ReadonlyJSONValue,
    type Mutator,
    type MutatorDefinition,
} from '@rocicorp/zero';
import { ulid } from 'ulid';
import { v4 as uuid, v5 as keyedUUID } from 'uuid';
import type { ClientContext, ModelContract } from './contract';
import { completeMutation, MutationResultError, type MutationClient } from './operation';
import { columnValue, encodeDate, freeze } from './validation';

/** Compiler-owned JSON nodes. Never accept these from mutation arguments. */
export type MutationNode =
    | { readonly op: 'literal'; readonly value: ReadonlyJSONValue }
    | { readonly op: 'local'; readonly name: string }
    | { readonly op: 'get' | 'has'; readonly base: MutationNode; readonly key: MutationNode }
    | { readonly op: 'validated'; readonly base: MutationNode }
    | { readonly op: 'timestamp' }
    | { readonly op: 'isset' | 'not'; readonly value: MutationNode }
    | {
          readonly op: 'list' | 'object';
          readonly items: readonly { readonly key: string | null; readonly value: MutationNode }[];
      }
    | {
          readonly op: 'binary';
          readonly operator: '===' | '!==' | '&&' | '||';
          readonly left: MutationNode;
          readonly right: MutationNode;
      }
    | { readonly op: 'create'; readonly model: string; readonly site: string; readonly data: MutationNode }
    | { readonly op: 'findUnique'; readonly model: string; readonly unique: MutationNode }
    | {
          readonly op: 'insertUnlessExists' | 'upsertUnique';
          readonly model: string;
          readonly unique: MutationNode;
          readonly site: string;
          readonly data: MutationNode;
      }
    | { readonly op: 'reload' | 'delete'; readonly record: MutationNode }
    | { readonly op: 'update'; readonly record: MutationNode; readonly data: MutationNode }
    | {
          readonly op: 'call';
          readonly parameters: readonly string[];
          readonly args: readonly MutationNode[];
          readonly body: readonly MutationStatement[];
      };
export type MutationStatement =
    | { readonly op: 'set'; readonly name: string; readonly value: MutationNode }
    | {
          readonly op: 'foreach';
          readonly value: MutationNode;
          readonly name: string;
          readonly max: number;
          readonly body: readonly MutationStatement[];
      }
    | { readonly op: 'expression' | 'return'; readonly value: MutationNode }
    | { readonly op: 'guard'; readonly index: number }
    | {
          readonly op: 'if';
          readonly condition: MutationNode;
          readonly then: readonly MutationStatement[];
          readonly else: readonly MutationStatement[];
      };
export interface ControllerMutationContract {
    readonly action: string;
    readonly version: string;
    readonly optimism: 'generated' | 'custom' | 'disabled';
    readonly returns: boolean;
    readonly parameters: Readonly<Record<string, { readonly kind: string; readonly type: string }>>;
    readonly operations: readonly MutationStatement[];
    readonly sites: Readonly<Record<string, 'uuid' | 'ulid'>>;
}
export type ControllerMutationWire = {
    readonly version: string;
    readonly input: Readonly<Record<string, ReadonlyJSONValue>>;
    readonly route: Readonly<Record<string, string | number | boolean>>;
    readonly prepared: { readonly ids: Readonly<Record<string, string>>; readonly timestamp: number };
};
export interface MutationCodec<T> {
    parse(value: unknown): T;
}
export interface ControllerMutationOptions<Input, ServerResult, ClientResult> {
    readonly input: MutationCodec<Input>;
    /** Runs before native request creation, e.g. Date -> epoch milliseconds. */
    readonly encode: (input: Input) => Readonly<Record<string, ReadonlyJSONValue>>;
    readonly serverResult: MutationCodec<ServerResult>;
    readonly clientResult: MutationCodec<ClientResult>;
    readonly optimistic?: (options: {
        tx: Transaction<Schema>;
        args: ControllerMutationWire;
        ctx: ClientContext;
    }) => Promise<ClientResult>;
}

function json(value: unknown): asserts value is ReadonlyJSONValue {
    if (value === null || typeof value === 'string' || typeof value === 'boolean') return;
    if (typeof value === 'number' && Number.isFinite(value)) return;
    if (Array.isArray(value)) {
        for (const item of value) json(item);
        return;
    }
    if (typeof value === 'object' && value && Object.getPrototypeOf(value) === Object.prototype) {
        for (const item of Object.values(value)) json(item);
        return;
    }
    throw new Error('Mutation arguments/results must be JSON; encode dates before preparation.');
}

/** PHP truthiness, notably "0", empty arrays, and empty associative arrays. */
export function mutationTruthy(value: unknown): boolean {
    if (value === null || value === undefined || value === false || value === 0 || value === '' || value === '0')
        return false;
    if (typeof value === 'object') return Object.keys(value).length !== 0;
    return true;
}
function identical(a: unknown, b: unknown): boolean {
    if (a === b) return true;
    if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') return false;
    const keys = Object.keys(a),
        other = Object.keys(b);
    return (
        keys.length === other.length &&
        keys.every((key, i) => key === other[i] && identical(Reflect.get(a, key), Reflect.get(b, key)))
    );
}

function jsonObject(value: unknown): Record<string, ReadonlyJSONValue> {
    json(value);
    if (value === null || typeof value !== 'object' || Array.isArray(value)) throw new Error('Expected a JSON object.');
    return value as Record<string, ReadonlyJSONValue>;
}
function propertyKey(value: unknown): string | number {
    if (typeof value !== 'string' && typeof value !== 'number') throw new Error('Expected a string or numeric key.');
    return value;
}
function writeData(model: ModelContract, value: unknown) {
    const data = jsonObject(value);
    const result: Record<string, ReadonlyJSONValue> = {};
    for (const key of Object.keys(data)) {
        if (!model.writable.includes(key)) throw new Error(`Protected mutation field ${key}`);
        const column = model.columns[key]!;
        const fieldValue = column.date && typeof data[key] === 'string' ? new Date(data[key]) : data[key];
        result[key] = columnValue(fieldValue, column, key, column.date ? fieldValue instanceof Date : true);
    }
    return result;
}

/** No private queue/cache: all reads/writes are on the native transaction. */
export async function runControllerMutation<S extends Schema>(
    schema: S,
    models: Readonly<Record<string, ModelContract>>,
    contract: ControllerMutationContract,
    tx: Transaction<S>,
    args: ControllerMutationWire,
    ctx: ClientContext,
): Promise<ReadonlyJSONValue | undefined> {
    // One dynamic-schema boundary: compiler/model metadata select the native table.
    const builder = createBuilder(schema as Schema);
    const native = tx as unknown as Transaction<Schema>;
    const locals: Record<string, unknown> = Object.create(null);
    const records = new WeakMap<object, ModelContract>();
    let itemKey: string | undefined;
    const modelFor = (type: string) => {
        const model = models[type.split('\\').at(-1)!];
        if (!model) throw new Error(`Unknown compiled model ${type}`);
        return model;
    };
    // Pre-write prerequisites only. A missing bound row is an intentional no-op,
    // not an error that prevents the intent from being sent to the server.
    for (const [name, parameter] of Object.entries(contract.parameters)) {
        if (parameter.kind === 'model') {
            const model = modelFor(parameter.type);
            const row = await native.run(builder[model.table]!.where(model.key, args.route[name]).one());
            if (!row) return undefined;
            const loaded = { ...jsonObject(row) };
            locals[name] = loaded;
            records.set(loaded, model);
        } else if (parameter.kind === 'request') locals[name] = args.input;
        else if (parameter.kind === 'scalar') locals[name] = args.route[name];
        else locals[name] = null;
    }
    const expression = async (node: MutationNode, scope: Record<string, unknown>): Promise<unknown> => {
        const e = (child: MutationNode) => expression(child, scope);
        switch (node.op) {
            case 'literal':
                return node.value;
            case 'local':
                return scope[node.name];
            case 'get': {
                const base = await e(node.base),
                    key = await e(node.key);
                if (base === null || base === undefined) return null;
                if (typeof base !== 'object') throw new Error('Invalid compiled property access.');
                const property = propertyKey(key);
                return Object.hasOwn(base, property) ? (Reflect.get(base, property) ?? null) : null;
            }
            case 'validated':
                return e(node.base);
            case 'timestamp':
                return args.prepared.timestamp;
            case 'isset':
                return (await e(node.value)) != null;
            case 'has': {
                const key = await e(node.key);
                return Object.hasOwn(jsonObject(await e(node.base)), propertyKey(key));
            }
            case 'not':
                return !mutationTruthy(await e(node.value));
            case 'list': {
                const values = [];
                for (const item of node.items) values.push(await e(item.value));
                return values;
            }
            case 'object': {
                const entries = [];
                for (const item of node.items) entries.push([item.key, await e(item.value)]);
                return Object.fromEntries(entries);
            }
            case 'binary': {
                const left = await e(node.left);
                if (node.operator === '&&') return mutationTruthy(left) && mutationTruthy(await e(node.right));
                if (node.operator === '||') return mutationTruthy(left) || mutationTruthy(await e(node.right));
                const equal = identical(left, await e(node.right));
                return node.operator === '===' ? equal : !equal;
            }
            case 'findUnique':
            case 'insertUnlessExists':
            case 'upsertUnique': {
                const model = models[node.model]!;
                const unique =
                    node.op === 'findUnique'
                        ? jsonObject(await e(node.unique))
                        : writeData(model, await e(node.unique));
                let query = builder[model.table]!;
                if (Object.keys(unique).length === 0) throw new Error('Empty unique key.');
                for (const [field, value] of Object.entries(unique)) {
                    if (!Object.hasOwn(model.columns, field)) throw new Error('Unknown unique key field.');
                    if (typeof value !== 'string' && typeof value !== 'boolean' && !Number.isSafeInteger(value))
                        throw new Error('Invalid unique key value.');
                    query = query.where(field, value);
                }
                const found = await native.run(query.one());
                const row = found ? { ...jsonObject(found) } : null;
                if (row) records.set(row, model);
                if (node.op === 'findUnique') return row;
                const data = writeData(model, await e(node.data));
                if (Object.keys(data).some((field) => Object.hasOwn(unique, field)))
                    throw new Error('Unique keys and write fields must be disjoint.');
                if (row) {
                    if (node.op === 'upsertUnique') {
                        if (model.timestamps.updated)
                            data[model.timestamps.updated] = encodeDate(
                                new Date(args.prepared.timestamp),
                                model.columns[model.timestamps.updated]!.dateFormat,
                            );
                        await native.mutate[model.table]!.update({ ...data, [model.key]: row[model.key] });
                        Object.assign(row, data);
                    }
                    return row;
                }
                // Cache absence is speculative only. Server conflict handling never
                // overwrites a preference for insertUnlessExists.
                return e({
                    op: 'create',
                    model: node.model,
                    site: node.site,
                    data: { op: 'literal', value: { ...unique, ...data } },
                });
            }
            case 'create': {
                const model = models[node.model]!;
                const row = writeData(model, await e(node.data));
                for (const [field, column] of Object.entries(model.columns)) {
                    if (!(field in row) && column.hasDefault) row[field] = structuredClone(column.default);
                    else if (!(field in row) && column.nullable) row[field] = null;
                }
                for (const [field, source] of Object.entries(model.serverFill)) {
                    const value = ctx[source];
                    if (value === undefined) throw new Error(`Missing trusted context ${source}`);
                    row[field] = value;
                }
                const id = args.prepared.ids[node.site];
                if (!id) throw new Error('Missing prepared identity.');
                row[model.key] = itemKey === undefined ? id : keyedUUID(itemKey, id);
                for (const field of [model.timestamps.created, model.timestamps.updated])
                    if (field)
                        row[field] = encodeDate(new Date(args.prepared.timestamp), model.columns[field]!.dateFormat);
                await native.mutate[model.table]!.insert(row);
                records.set(row, model);
                return row;
            }
            case 'reload': {
                const record = jsonObject(await e(node.record)),
                    model = records.get(record)!;
                const row = await native.run(
                    builder[model.table]!.where(model.key, propertyKey(record[model.key])).one(),
                );
                if (!row) throw new Error('Late mutation prerequisite is unsupported.');
                const loaded = { ...row };
                records.set(loaded, model);
                return loaded;
            }
            case 'update': {
                const record = jsonObject(await e(node.record)),
                    model = records.get(record)!;
                const data = writeData(model, await e(node.data));
                if (model.timestamps.updated)
                    data[model.timestamps.updated] = encodeDate(
                        new Date(args.prepared.timestamp),
                        model.columns[model.timestamps.updated]!.dateFormat,
                    );
                await native.mutate[model.table]!.update({ ...data, [model.key]: record[model.key] });
                Object.assign(record, data);
                return true;
            }
            case 'delete': {
                const record = jsonObject(await e(node.record)),
                    model = records.get(record)!;
                await native.mutate[model.table]!.delete({ [model.key]: record[model.key] });
                return true;
            }
            case 'call': {
                const values: unknown[] = [];
                for (const arg of node.args) values.push(await e(arg));
                return (
                    await block(
                        node.body,
                        Object.fromEntries(node.parameters.map((name: string, i: number) => [name, values[i]])),
                    )
                )?.value;
            }
            default:
                throw new Error('Unsupported compiled mutation expression.');
        }
    };
    const block = async (
        operations: readonly MutationStatement[],
        scope: Record<string, unknown>,
    ): Promise<{ value: unknown } | undefined> => {
        for (const op of operations) {
            switch (op.op) {
                case 'set':
                    scope[op.name] = await expression(op.value, scope);
                    break;
                case 'expression':
                    await expression(op.value, scope);
                    break;
                case 'guard':
                    break; // Explicit authoritative boundary, never a browser permission check.
                case 'return':
                    return { value: await expression(op.value, scope) };
                case 'foreach': {
                    const items = await expression(op.value, scope);
                    if (!Array.isArray(items) || items.length > op.max)
                        throw new Error('Invalid bounded mutation list.');
                    const keys = new Set<string>();
                    for (const value of items) {
                        const key = jsonObject(value).key;
                        if (
                            typeof key !== 'string' ||
                            key === '' ||
                            new TextEncoder().encode(key).length > 256 ||
                            keys.has(key)
                        )
                            throw new Error(
                                'Repeated items require distinct nonempty string keys of at most 256 bytes.',
                            );
                        keys.add(key);
                    }
                    for (const value of items) {
                        scope[op.name] = value;
                        itemKey = jsonObject(value).key as string;
                        try {
                            const result = await block(op.body, scope);
                            if (result) return result;
                        } finally {
                            itemKey = undefined;
                        }
                    }
                    break;
                }
                case 'if': {
                    const result = await block(
                        mutationTruthy(await expression(op.condition, scope)) ? op.then : op.else,
                        scope,
                    );
                    if (result) return result;
                    break;
                }
                default:
                    throw new Error('Unsupported compiled mutation operation.');
            }
        }
        return undefined;
    };
    const value = (await block(contract.operations, locals))?.value;
    if (!contract.returns) return undefined;
    const result = value ?? null;
    json(result);
    return result;
}

/** Register flat m_<hash> names in the existing native registry. No second transport. */
export function createControllerMutations<S extends Schema>(
    schema: S,
    models: Readonly<Record<string, ModelContract>>,
    contracts: Readonly<Record<string, ControllerMutationContract>>,
    options: Readonly<
        Record<string, Pick<ControllerMutationOptions<never, unknown, unknown>, 'clientResult' | 'optimistic'>>
    >,
) {
    const definitions: Record<
        string,
        MutatorDefinition<ControllerMutationWire, ControllerMutationWire, ClientContext>
    > = {};
    for (const [name, contract] of Object.entries(contracts)) {
        if (!/^m_[a-f0-9]{32}$/.test(name)) throw new Error('Invalid controller mutation registration name.');
        const config = options[name];
        if (!config || (contract.optimism === 'custom' && !config.optimistic))
            throw new Error(`Missing explicit codecs/projection for ${name}`);
        // A declared no-op must remain a valid native invocation, including its result contract.
        if (
            contract.optimism === 'disabled' ||
            (contract.optimism === 'generated' &&
                Object.values(contract.parameters).some((parameter) => parameter.kind === 'model'))
        )
            config.clientResult.parse(undefined);
        definitions[name] = defineMutator(
            async ({ tx, args, ctx }: { tx: Transaction<S>; args: ControllerMutationWire; ctx: ClientContext }) => {
                if (args.version !== contract.version) throw new Error('Unsupported mutation version.');
                const result =
                    contract.optimism === 'disabled'
                        ? undefined
                        : contract.optimism === 'custom'
                          ? await config.optimistic!({ tx: tx as unknown as Transaction<Schema>, args, ctx })
                          : await runControllerMutation(schema, models, contract, tx, args, ctx);
                if (result !== undefined) json(result);
                config.clientResult.parse(result);
                return result === undefined ? undefined : result;
            },
        );
    }
    const mutators = defineMutators(definitions);
    return {
        definitions,
        mutators,
        bind<Input, ServerResult, ClientResult>(
            name: string,
            config: ControllerMutationOptions<Input, ServerResult, ClientResult>,
        ) {
            const contract = contracts[name];
            if (!contract) throw new Error('Unknown controller mutation.');
            const mutator = mutators[name] as unknown as Mutator<ControllerMutationWire, S, ClientContext>;
            return {
                serverResultSchema: config.serverResult,
                clientResultSchema: config.clientResult,
                prepare(input: Input, route: ControllerMutationWire['route'] = {}) {
                    const encoded = config.encode(config.input.parse(input));
                    json(encoded);
                    json(route);
                    const ids = Object.fromEntries(
                        Object.entries(contract.sites).map(([site, generator]) => [
                            site,
                            generator === 'uuid' ? uuid() : ulid(),
                        ]),
                    );
                    const wire = freeze(
                        structuredClone({
                            version: contract.version,
                            input: encoded,
                            route,
                            prepared: { ids, timestamp: Date.now() },
                        }),
                    );
                    return mutator(wire);
                },
                async execute(
                    client: MutationClient<S>,
                    input: Input,
                    route: ControllerMutationWire['route'] = {},
                ): Promise<ServerResult> {
                    const data = await completeMutation(client.mutate(this.prepare(input, route)));
                    try {
                        return config.serverResult.parse(data);
                    } catch (error) {
                        throw new MutationResultError(error);
                    }
                },
            };
        },
    };
}
