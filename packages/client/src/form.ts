import type { Constraints, FieldDefinition, FormDefinition } from './form-contract';
import type { Column, ContractMap, ModelContract, Operation, RelatedName } from './contract';
import type { NestedSelections } from './mutation';
import { decodeColumn, fail, object } from './validation';

/** Formbraid edits date strings; the mutation boundary restores native Dates. */
export type MutationDraft<T> = T extends Date
    ? string
    : T extends readonly (infer Item)[]
      ? MutationDraft<Item>[]
      : T extends object
        ? { -readonly [K in keyof T]?: MutationDraft<T[K]> }
        : T extends string
          ? string
          : T extends number
            ? T | ''
            : T;

type ComponentFor<C extends Column> = C['date'] extends true
    ? 'DateField'
    : C['enum'] extends readonly (string | number)[]
      ? 'SelectField'
      : C extends { readonly cast: 'boolean' }
        ? 'CheckboxField'
        : C extends { readonly cast: 'integer' | 'float' }
          ? 'NumberField'
          : C extends { readonly cast: 'string' }
            ? 'TextField'
            : C extends { readonly cast: 'array' }
              ? 'ArrayField'
              : C extends { readonly cast: 'object' }
                ? 'ObjectField'
                : C['type'] extends 'string'
                  ? 'TextField'
                  : C['type'] extends 'number'
                    ? 'NumberField'
                    : C['type'] extends 'boolean'
                      ? 'CheckboxField'
                      : 'ObjectField';
type FieldPaths<T, Depth extends unknown[] = []> = [T] extends [never]
    ? never
    : Depth['length'] extends 8
      ? never
      : T extends Date
        ? never
        : T extends readonly (infer Item)[]
          ? `*.${FieldPaths<NonNullable<Item>, [...Depth, unknown]>}`
          : T extends object
            ? { [K in keyof T & string]: K | `${K}.${FieldPaths<NonNullable<T[K]>, [...Depth, unknown]>}` }[keyof T &
                  string]
            : never;
type FieldComponent<
    D extends ContractMap,
    N extends keyof D,
    P extends string,
    Input,
> = P extends `${infer Head}.${infer Rest}`
    ? Head extends keyof D[N]['relations'] & keyof Input & string
        ? D[N]['relations'][Head]['cardinality'] extends 'many'
            ? Rest extends `*.${infer Child}`
                ? FieldComponent<
                      D,
                      RelatedName<D, N, Head>,
                      Child,
                      NonNullable<Input[Head]> extends readonly (infer Item)[] ? Item : never
                  >
                : never
            : FieldComponent<D, RelatedName<D, N, Head>, Rest, NonNullable<Input[Head]>>
        : never
    : P extends 'id'
      ? Input extends { readonly id: unknown }
          ? ComponentFor<D[N]['columns'][D[N]['key']]>
          : D[N]['idGenerator'] extends null
            ? Input extends { readonly id?: string }
                ? ComponentFor<D[N]['columns'][D[N]['key']]>
                : never
            : never
      : P extends D[N]['writable'][number] & keyof Input & keyof D[N]['columns']
        ? ComponentFor<D[N]['columns'][P]>
        : P extends keyof D[N]['relations'] & keyof Input
          ? D[N]['relations'][P]['cardinality'] extends 'many'
              ? 'ArrayField'
              : never
          : never;

export type MutationFields<D extends ContractMap, N extends keyof D, Input> = {
    readonly [
        P in FieldPaths<Input> as [FieldComponent<D, N, P, Input>] extends [never] ? never : P
    ]: FieldDefinition & { readonly name: P; readonly component: FieldComponent<D, N, P, Input> };
};
export type MutationDefinition<D extends ContractMap, N extends keyof D, Input> = Pick<
    FormDefinition<MutationDraft<Input> & object, MutationFields<D, N, Input>, Record<string, never>>,
    'id' | 'defaultValues' | 'fields' | 'groups'
>;

const components = {
    string: 'TextField',
    number: 'NumberField',
    integer: 'NumberField',
    boolean: 'CheckboxField',
    date: 'DateField',
    enum: 'SelectField',
    array: 'ArrayField',
    object: 'ObjectField',
} as const;

function fieldType(column: Column): FieldDefinition['type'] {
    if (column.date) return 'date';
    if (column.enum) return 'enum';
    if (column.cast === 'integer') return 'integer';
    if (column.cast === 'float') return 'number';
    if (column.cast === 'array') return 'array';
    if (column.cast === 'object') return 'object';
    if (column.cast) return column.cast;
    return column.type === 'json' ? 'object' : column.type;
}

function fieldDefinition(name: string, column: Column, rules: readonly string[], required: boolean): FieldDefinition {
    const type = fieldType(column);
    const constraints: { -readonly [K in keyof Constraints]: Constraints[K] } = { required };
    const numeric = column.type === 'number' || column.cast === 'integer' || column.cast === 'float';
    if (column.enum) constraints.options = [...column.enum];
    for (const rule of rules) {
        const [kind, parameter] = rule.split(':');
        if ((kind === 'min' || kind === 'max') && parameter !== undefined && Number.isFinite(Number(parameter))) {
            const key = numeric
                ? kind
                : type === 'array' || type === 'object'
                  ? kind === 'min'
                      ? 'minItems'
                      : 'maxItems'
                  : kind === 'min'
                    ? 'minLength'
                    : 'maxLength';
            constraints[key] = Number(parameter);
        } else if (kind === 'in' && parameter !== undefined) {
            const allowed = parameter.split(',');
            constraints.options = column.enum
                ? column.enum.filter((value) => allowed.includes(String(value)))
                : allowed.map((value) => (numeric ? Number(value) : value));
        }
    }
    return {
        name,
        type,
        component: components[type],
        label: name
            .split('.')
            .at(-1)!
            .replaceAll('_', ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase()),
        props: {},
        constraints,
        nullable: column.nullable,
        serverRules: [...rules],
    };
}

function draftValue(value: unknown, column: Column): unknown {
    return column.date && value instanceof Date ? value.toISOString() : value;
}

/** One traversal owns writable fields, link-key exclusions, and nested metadata. */
export function mutationForm(
    definitions: ContractMap,
    root: ModelContract,
    operation: Operation,
    selections: NestedSelections,
    bound: boolean,
    defaults: Readonly<Record<string, unknown>>,
) {
    const fields: string[] = [];
    const columns: Record<string, Column> = { ...root.columns };
    const definitionsByPath: Record<string, FieldDefinition> = {};
    const visit = (
        model: ModelContract,
        action: Operation,
        nested: NestedSelections,
        prefix: string,
        excluded: readonly string[] = [],
        includeId = false,
        depth = 0,
    ): ((initial: Readonly<Record<string, unknown>>) => Record<string, unknown>) => {
        if (depth > 8) fail('relations', 'Nested mutations exceed the maximum depth.');
        const ownBindings = Object.keys(nested).flatMap((name) =>
            model.relations[name]?.kind === 'belongsTo' ? [...model.relations[name].path[0]!.source] : [],
        );
        const writable = (action === 'create' || action === 'update' ? model.writable : []).filter(
            (field) => !excluded.includes(field) && !ownBindings.includes(field),
        );
        if (includeId)
            definitionsByPath[prefix + 'id'] = fieldDefinition(prefix + 'id', model.columns[model.key]!, [], true);
        for (const field of writable) {
            const path = prefix + field;
            const column = model.columns[field]!;
            const rules = model.validation[action]?.[field] ?? [];
            const required =
                (rules.includes('required') && !rules.includes('sometimes')) ||
                (action === 'create' && !column.nullable && !column.hasDefault);
            const definition = fieldDefinition(path, column, rules, required);
            fields.push(path);
            columns[path] = column;
            definitionsByPath[path] = definition;
        }
        const children = Object.entries(nested).map(([name, spec]) => {
            const relation = model.relations[name]!;
            const path = `${prefix}${name}`;
            const many = relation.cardinality === 'many';
            const childPrefix = `${path}.${many ? '*.' : ''}`;
            const target = definitions[relation.model]!;
            if (many)
                definitionsByPath[path] = {
                    name: path,
                    type: 'array',
                    component: components.array,
                    label: name.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()),
                    props: {},
                    constraints: { maxItems: 100 },
                    nullable: false,
                    serverRules: [],
                };
            const initialize = visit(
                target,
                spec.operation,
                spec.relations,
                childPrefix,
                relation.kind === 'belongsTo' ? [] : relation.path[0]!.dest,
                spec.operation === 'update' || !target.idGenerator,
                depth + 1,
            );
            // An omitted optional update relation is not a request to edit that record.
            if (spec.operation === 'update') {
                for (const [field, definition] of Object.entries(definitionsByPath)) {
                    if (field.startsWith(childPrefix) && definition.constraints.required)
                        definitionsByPath[field] = {
                            ...definition,
                            constraints: {
                                ...definition.constraints,
                                required: false,
                                requiredWhen: [{ field: many ? `${path}.*` : path, operator: 'present', values: [] }],
                            },
                        };
                }
            }
            return { name, path, many, action: spec.operation, initialize };
        });
        return (initial) => {
            const values: Record<string, unknown> = {};
            if (includeId) values.id = initial.id ?? '';
            for (const field of writable) {
                const column = model.columns[field]!;
                if (field in initial) values[field] = draftValue(initial[field], column);
                else if (column.hasDefault)
                    values[field] = draftValue(decodeColumn(column.default, column, field), column);
                else if (column.nullable) values[field] = null;
                else {
                    const type = definitionsByPath[prefix + field]!.type;
                    values[field] =
                        type === 'boolean'
                            ? false
                            : type === 'number' || type === 'integer'
                              ? 0
                              : type === 'array'
                                ? []
                                : type === 'object'
                                  ? {}
                                  : '';
                }
            }
            for (const child of children) {
                const value = initial[child.name];
                if (child.many)
                    values[child.name] = Array.isArray(value)
                        ? value.map((item) => child.initialize(object(item, child.path)))
                        : [];
                else if (child.action === 'create' || value !== undefined)
                    values[child.name] = child.initialize(value === undefined ? {} : object(value, child.path));
            }
            return values;
        };
    };
    const initialize = visit(
        root,
        operation,
        selections,
        '',
        [],
        !bound && (operation !== 'create' || !root.idGenerator),
    );
    const defaultValues = initialize(defaults);
    return {
        fields: [...new Set(fields)],
        columns,
        definition: {
            id: `${root.name}.${operation}:${JSON.stringify(selections)}`,
            defaultValues,
            fields: definitionsByPath,
            groups: {},
        },
    };
}

/** Convert only model date columns; JSON payloads retain their application shape. */
export function mutationFormInput(
    definitions: ContractMap,
    model: ModelContract,
    nested: NestedSelections,
    input: unknown,
    prefix = '',
    depth = 0,
): Record<string, unknown> {
    if (depth > 8) fail('relations', 'Nested mutations exceed the maximum depth.');
    const values = { ...object(input, prefix || 'input') };
    for (const [field, column] of Object.entries(model.columns)) {
        const value = values[field];
        if (!column.date || value === undefined || value === null) continue;
        if (value === '' && column.nullable) values[field] = null;
        else if (typeof value === 'string') {
            const date = new Date(value);
            if (!Number.isFinite(date.getTime())) fail(prefix + field, 'Expected a valid date.');
            values[field] = date;
        }
    }
    for (const [name, spec] of Object.entries(nested)) {
        if (!(name in values)) continue;
        const relation = model.relations[name]!;
        const child = (value: unknown, path: string) =>
            mutationFormInput(definitions, definitions[relation.model]!, spec.relations, value, path, depth + 1);
        values[name] =
            relation.cardinality === 'many' && Array.isArray(values[name])
                ? values[name].map((value, index) => child(value, `${prefix}${name}.${index}.`))
                : child(values[name], `${prefix}${name}.`);
    }
    return values;
}
