type FormJSON = null | string | number | boolean | FormJSON[] | { readonly [key: string]: FormJSON };

// Structural producer types: consuming generated forms never loads FormBraid.
export interface Constraints {
    readonly required?: boolean;
    readonly requiredWhen?: readonly {
        readonly field: string;
        readonly operator: 'present';
        readonly values: readonly FormJSON[];
    }[];
    readonly minLength?: number;
    readonly maxLength?: number;
    readonly min?: number;
    readonly max?: number;
    readonly minItems?: number;
    readonly maxItems?: number;
    readonly options?: readonly (string | number)[];
}
export interface FieldDefinition {
    readonly name: string;
    readonly type: 'string' | 'integer' | 'number' | 'boolean' | 'date' | 'enum' | 'array' | 'object';
    readonly component: string;
    readonly label: string;
    readonly props: Readonly<Record<string, FormJSON>>;
    readonly constraints: Constraints;
    readonly nullable: boolean;
    readonly serverRules: readonly string[];
}
export interface FormDefinition<TValues, TFields, TGroups> {
    readonly id: string;
    readonly defaultValues: TValues;
    readonly fields: TFields;
    readonly groups: TGroups;
}
export type SubmissionResult =
    | { readonly status: 'success' }
    | { readonly status: 'cancelled' }
    | { readonly status: 'invalid'; readonly errors: Readonly<Record<string, string | readonly string[]>> }
    | { readonly status: 'failure'; readonly error: unknown };
export interface CallbackSubmission<T> {
    readonly submit: (values: T, context: { readonly signal: AbortSignal }) => Promise<SubmissionResult>;
}
export interface FormDescriptor<TValues, TFields, TGroups> {
    readonly version: 1;
    readonly definition: FormDefinition<TValues, TFields, TGroups>;
    readonly defaultValues?: TValues;
    readonly submission: CallbackSubmission<TValues>;
}
