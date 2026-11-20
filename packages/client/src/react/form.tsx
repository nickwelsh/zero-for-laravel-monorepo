import {
    forwardRef,
    useImperativeHandle,
    useLayoutEffect,
    useRef,
    useState,
    type FormHTMLAttributes,
    type ReactNode,
} from 'react';
import { validationFields } from '../operation';
import type { NativeFormAction } from './form-action';

type Data = Record<string, unknown>;
type Errors = Record<string, string>;
type ResetOption = boolean | readonly string[];

/** Imperative ref and render-prop API. Data reads return raw DOM values, before transform/normalization. */
export interface FormRef {
    errors: Errors;
    hasErrors: boolean;
    processing: boolean;
    progress: null;
    wasSuccessful: boolean;
    recentlySuccessful: boolean;
    isDirty: boolean;
    getFormData(): FormData;
    getData(): Data;
    setError: { (field: string, message: string): void; (errors: Errors): void };
    clearErrors(...fields: string[]): void;
    reset(...fields: string[]): void;
    resetAndClearErrors(...fields: string[]): void;
    defaults(): void;
    submit(): Promise<void>;
}

const unsupported = [
    'method',
    'target',
    'encType',
    'headers',
    'queryStringArrayFormat',
    'errorBag',
    'showProgress',
    'invalidateCacheTags',
    'component',
    'instant',
    'optimistic',
    'options',
    'onBeforeUpdate',
    'onCancelToken',
    'onCancel',
    'onProgress',
    'onFlash',
    'onHttpException',
    'onNetworkError',
    'cancelOnUnmount',
    'validateFiles',
    'validationTimeout',
    'withAllErrors',
    'onSubmit',
    'only',
    'except',
    'replace',
    'preserveState',
    'preserveScroll',
    'preserveUrl',
    'reset',
    'viewTransition',
    'async',
    'forceFormData',
] as const;

export interface FormProps extends Omit<
    FormHTMLAttributes<HTMLFormElement>,
    (typeof unsupported)[number] | 'action' | 'children' | 'onError'
> {
    action: NativeFormAction;
    children: ReactNode | ((state: FormRef) => ReactNode);
    transform?: (data: Data) => Data;
    disableWhileProcessing?: boolean;
    resetOnSuccess?: ResetOption;
    resetOnError?: ResetOption;
    setDefaultsOnSuccess?: boolean;
    onBefore?: () => void | boolean;
    onStart?: () => void;
    onSuccess?: (result: unknown) => void | Promise<void>;
    onError?: (errors: Errors) => void | Promise<void>;
    onSubmitComplete?: (methods: Pick<FormRef, 'reset' | 'defaults'>) => void;
    onFinish?: () => void;
}

// The generic React entry must load without @inertiajs/core. Match its formDataToObject semantics:
// escaped dots, nested brackets, append names, last scalar wins, and dense numeric keys become arrays.
function fieldPath(name: string): string[] {
    return Array.from(name.matchAll(/\[([^\]]*)\]|((?:\\\.|[^.[\]])+)/g), (match) =>
        (match[1] ?? match[2]!).replaceAll('\\.', '.'),
    );
}

type Fields = { [key: string]: FormDataEntryValue | Fields };
function newFields(): Fields {
    return Object.create(null) as Fields;
}
function isFields(value: FormDataEntryValue | Fields | undefined): value is Fields {
    return typeof value === 'object' && Object.getPrototypeOf(value) === null;
}
function selectedFile(value: FormDataEntryValue): value is File {
    return typeof value !== 'string' && (value.name !== '' || value.size !== 0);
}
function unpack(fields: Fields): Data | unknown[] {
    const entries = Object.entries(fields).map(
        ([key, value]) => [key, isFields(value) ? unpack(value) : value] as const,
    );
    return entries.length && entries.every(([key], i) => key === String(i))
        ? entries.map(([, value]) => value)
        : Object.fromEntries(entries);
}
function dataObject(data: FormData): Data {
    const root = newFields();
    const appendPositions = new WeakMap<Fields, number>();
    for (const [name, value] of data) {
        if (typeof value !== 'string' && !selectedFile(value)) continue;
        const path = fieldPath(name);
        const append = path.at(-1) === '';
        if (append) path.pop();
        const key = path.pop();
        if (key === undefined) continue;
        let node = root;
        for (const part of path) {
            if (!isFields(node[part])) node[part] = newFields();
            node = node[part];
        }
        if (!append) {
            node[key] = value;
            appendPositions.delete(node);
            continue;
        }
        // The first [] after explicit indices compacts the existing numeric values (even sparse indices).
        let list = node[key];
        let next = isFields(list) ? appendPositions.get(list) : undefined;
        if (!isFields(list) || next === undefined) {
            const entries = isFields(list) ? Object.entries(list).filter(([index]) => /^\d+$/.test(index)) : [];
            list = node[key] = newFields();
            for (const [index, [, entry]] of entries.entries()) list[index] = entry;
            next = entries.length;
        }
        list[next] = value;
        appendPositions.set(list, next + 1);
    }
    return unpack(root) as Data;
}

function fingerprint(data: FormData) {
    return JSON.stringify(
        Array.from(data, ([name, value]) => [
            name,
            typeof value === 'string' ? value : [value.name, value.size, selectedFile(value) ? value.lastModified : 0],
        ]),
    );
}

function restore(form: HTMLFormElement, source: FormData, names: readonly string[] = []) {
    const offsets = new Map<string, number>();
    for (const field of form.elements) {
        if (
            !(
                field instanceof HTMLInputElement ||
                field instanceof HTMLSelectElement ||
                field instanceof HTMLTextAreaElement
            )
        )
            continue;
        const { name } = field;
        if (
            !name ||
            field.matches(':disabled') ||
            (names.length && !names.includes(name) && !names.includes(fieldPath(name).join('.')))
        )
            continue;
        const values = source.getAll(name);
        if (field instanceof HTMLInputElement && ['submit', 'image', 'reset', 'button'].includes(field.type)) continue;
        if (field instanceof HTMLInputElement && (field.type === 'checkbox' || field.type === 'radio')) {
            field.checked = values.includes(field.value);
        } else if (field instanceof HTMLInputElement && field.type === 'file') {
            field.value = '';
        } else if (field instanceof HTMLSelectElement && field.multiple) {
            for (const option of field.options) option.selected = values.includes(option.value);
        } else {
            const index = offsets.get(name) ?? 0;
            const text = values[index];
            field.value = typeof text === 'string' ? text : '';
            offsets.set(name, index + 1);
        }
    }
}

const fileMessage = 'Native Zero forms cannot submit files. Upload the file separately.';
function rejectFiles(value: unknown): void {
    if (value instanceof Blob) throw new Error(fileMessage);
    if (value && typeof value === 'object') Object.values(value).forEach(rejectFiles);
}
function formErrors(error: unknown): Errors {
    const entries = Object.entries(validationFields(error)).flatMap(([name, messages]) =>
        messages.length ? [[name || '_form', messages[0]!]] : [],
    );
    return entries.length
        ? Object.fromEntries(entries)
        : { _form: error instanceof Error ? error.message : String(error) };
}

const MountedForm = forwardRef<FormRef, FormProps>(function MountedForm(
    {
        action,
        children,
        transform,
        disableWhileProcessing,
        resetOnSuccess,
        resetOnError,
        setDefaultsOnSuccess,
        onBefore,
        onStart,
        onSuccess,
        onError,
        onSubmitComplete,
        onFinish,
        onInput,
        onChange,
        onReset,
        ...html
    },
    ref,
) {
    const element = useRef<HTMLFormElement>(null);
    const baseline = useRef<FormData | null>(null);
    const [initialAction] = useState(() => action);
    const inFlight = useRef(false);
    const recentTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [isDirty, setDirty] = useState(false);
    const [wasSuccessful, setSuccessful] = useState(false);
    const [recentlySuccessful, setRecent] = useState(false);
    const getFormData = () => (element.current ? new FormData(element.current) : new FormData());
    const getData = () => dataObject(getFormData());
    const updateDirty = () =>
        setDirty(baseline.current !== null && fingerprint(getFormData()) !== fingerprint(baseline.current));
    useLayoutEffect(() => {
        const form = element.current!;
        baseline.current ??= new FormData(form);
        const recovery = initialAction.takeRecovery();
        if (recovery) {
            restore(form, recovery.draft);
            setDirty(fingerprint(new FormData(form)) !== fingerprint(baseline.current));
            setErrors(formErrors(recovery.error));
        }
        return () => clearTimeout(recentTimer.current);
    }, [initialAction]);
    const reset = (...fields: string[]) => {
        if (element.current && baseline.current) restore(element.current, baseline.current, fields);
        updateDirty();
    };
    const maybeReset = (option: ResetOption | undefined) => {
        if (option === true) reset();
        else if (option && option.length) reset(...option);
    };
    const defaults = () => {
        baseline.current = getFormData();
        setDirty(false);
    };
    const clearErrors = (...names: string[]) =>
        setErrors((old) =>
            names.length ? Object.fromEntries(Object.entries(old).filter(([name]) => !names.includes(name))) : {},
        );
    const submit = async (submitter?: HTMLElement | null) => {
        const form = element.current;
        if (!form || inFlight.current) return;
        inFlight.current = true;
        let started = false;
        try {
            if (onBefore?.() === false || !element.current) return;
            const draft = new FormData(form);
            const data = submitter ? new FormData(form, submitter) : draft;
            clearTimeout(recentTimer.current);
            setRecent(false);
            setSuccessful(false);
            setProcessing(true);
            started = true;
            onStart?.();
            let result: unknown;
            try {
                const modifier = ['formaction', 'formmethod', 'formenctype', 'formtarget'].find((attr) =>
                    submitter?.hasAttribute(attr),
                );
                if (modifier)
                    throw new Error(`Native Zero forms do not support the submitter's ${modifier} attribute.`);
                for (const value of data.values()) if (selectedFile(value)) throw new Error(fileMessage);
                const values = dataObject(data);
                const transformed = transform ? transform(values) : values;
                rejectFiles(transformed);
                result = await action.submit(action.normalize(transformed), { draft });
            } catch (error) {
                if (!element.current) return;
                const fields = formErrors(error);
                setErrors(fields);
                await onError?.(fields);
                if (element.current) maybeReset(resetOnError);
                return;
            }
            if (!element.current) return;
            setErrors({});
            setSuccessful(true);
            setRecent(true);
            recentTimer.current = setTimeout(() => setRecent(false), 2000);
            await onSuccess?.(result);
            if (!element.current) return;
            onSubmitComplete?.({ reset, defaults });
            maybeReset(resetOnSuccess);
            if (setDefaultsOnSuccess) defaults();
        } finally {
            inFlight.current = false;
            if (started && element.current) {
                setProcessing(false);
                onFinish?.();
            }
        }
    };

    const state: FormRef = {
        errors,
        hasErrors: Object.keys(errors).length > 0,
        processing,
        progress: null,
        isDirty,
        wasSuccessful,
        recentlySuccessful,
        getFormData,
        getData,
        reset,
        defaults,
        submit,
        clearErrors,
        setError: (key: string | Errors, message?: string) =>
            setErrors((old) => ({ ...old, ...(typeof key === 'string' ? { [key]: message! } : key) })),
        resetAndClearErrors: (...fields) => {
            reset(...fields);
            clearErrors(...fields);
        },
    };
    useImperativeHandle(ref, () => state);
    return (
        <form
            {...html}
            ref={element}
            inert={html.inert || (disableWhileProcessing && processing)}
            onChange={(event) => {
                onChange?.(event);
                updateDirty();
            }}
            onInput={(event) => {
                onInput?.(event);
                updateDirty();
            }}
            onReset={(event) => {
                onReset?.(event);
                if (!event.defaultPrevented) {
                    event.preventDefault();
                    reset();
                }
            }}
            onSubmit={(event) => {
                event.preventDefault();
                void submit(event.nativeEvent.submitter);
            }}
        >
            {typeof children === 'function' ? children(state) : children}
        </form>
    );
});

/** A generated action is the only submission path. Changing action/route drops the old form's observers and DOM. */
export const Form = forwardRef<FormRef, FormProps>(function Form(props, ref) {
    if (!props.action || typeof props.action.submit !== 'function')
        throw new Error('Native Zero forms require a generated .form() action, not an HTTP URL.');
    for (const key of unsupported) if (key in props) throw new Error(`Native Zero forms do not support '${key}'.`);
    return <MountedForm key={props.action.key} {...props} ref={ref} />;
});
