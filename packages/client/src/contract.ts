import type { ReadonlyJSONValue } from '@rocicorp/zero';

export interface Column {
    readonly type: 'string' | 'number' | 'boolean' | 'json';
    readonly nullable: boolean;
    readonly date: boolean;
    readonly dateFormat?: 'Y-m-d' | 'Y-m-d H:i:s' | 'Y-m-d H:i:s.u';
    readonly enum: readonly (string | number)[] | null;
    readonly hasDefault: boolean;
    readonly default: ReadonlyJSONValue;
    readonly cast?: 'boolean' | 'integer' | 'float' | 'string' | 'array' | 'object';
}

export interface Relation {
    readonly model: string;
    readonly cardinality: 'one' | 'many';
    readonly path: readonly {
        readonly source: readonly string[];
        readonly dest: readonly string[];
        readonly table: string;
    }[];
}

export type ClientContext = Readonly<Record<string, string | number | boolean | null>> & {
    readonly user_id: string | number | null;
};

/** Schema, casts, and visibility shared by generated controller contracts. */
export interface ModelContract {
    readonly search?: {
        readonly index: string;
        readonly key: string;
        readonly queryBy: string;
        readonly fields: Readonly<Record<string, string>>;
        readonly facets: readonly string[];
        readonly sorts: readonly string[];
    };
    readonly name: string;
    readonly table: string;
    readonly key: string;
    readonly columns: Readonly<Record<string, Column>>;
    readonly authorization: readonly {
        readonly field: string;
        readonly op: string;
        readonly value: { readonly context: string } | { readonly literal: string | number | boolean | null };
    }[];
    readonly authorizationRelations?: readonly string[];
    readonly serverFill: Readonly<Record<string, string>>;
    readonly writable: readonly string[];
    readonly idGenerator: 'uuid' | 'ulid' | null;
    readonly timestamps: {
        readonly created: string | null;
        readonly updated: string | null;
        readonly deleted: string | null;
    };
    readonly relations: Readonly<Record<string, Relation>>;
}

export type ContractMap = Readonly<Record<string, ModelContract>>;
type BaseValue<C extends Column> = C['date'] extends true
    ? Date
    : C['enum'] extends readonly (string | number)[]
      ? C['enum'][number]
      : C extends { readonly cast: 'boolean' }
        ? boolean
        : C extends { readonly cast: 'integer' | 'float' }
          ? number
          : C extends { readonly cast: 'string' }
            ? string
            : C extends { readonly cast: 'array' | 'object' }
              ? ReadonlyJSONValue
              : C['type'] extends 'string'
                ? string
                : C['type'] extends 'number'
                  ? number
                  : C['type'] extends 'boolean'
                    ? boolean
                    : ReadonlyJSONValue;
export type ColumnValue<C extends Column> = BaseValue<C> | (C['nullable'] extends true ? null : never);
/** Augment with DTO shapes, including types emitted by Laravel Data/TypeScript Transformer. */
export interface ModelTypes {}
export type FieldValue<
    D extends ContractMap,
    N extends keyof D,
    F extends keyof D[N]['columns'],
> = N extends keyof ModelTypes
    ? F extends keyof ModelTypes[N]
        ? ModelTypes[N][F] | (D[N]['columns'][F]['nullable'] extends true ? null : never)
        : ColumnValue<D[N]['columns'][F]>
    : ColumnValue<D[N]['columns'][F]>;
export type ModelRow<D extends ContractMap, N extends keyof D> = {
    readonly [F in keyof D[N]['columns']]: FieldValue<D, N, F>;
};
