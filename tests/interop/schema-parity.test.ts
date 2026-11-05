import { describe, expect, expectTypeOf, it } from 'vite-plus/test';
import { defineForm } from '@formbraid/core';
import * as EffectSchema from 'effect/Schema';
import { validationFields } from '../../packages/client/src/operation';
import * as effect from './schema-parity-effect.generated';
import * as zod from './schema-parity-zod.generated';

const uuid = '0193b4ec-7050-7a89-8bcb-3a3397a63781';
const instant = '2024-02-29T12:34:56.123Z';
const date = new Date(instant);
const fields = { required: 'yes', nullable: null };
const routed = { direction: 'asc', priority: 2 };
const draft = { title: 'Draft', at: date, day: date, items: [{ at: date }] };
const wireDraft = { title: 'Draft', at: instant, day: '2024-02-29', items: [{ at: instant }] };
const scalars = { text: '0', active: false, score: 2.5, limit: -7 };

// Independent expectations, not "Effect agrees with Zod": shared regressions must also fail.
// Boundaries distinguish safe integers, code points, real calendar dates, and omission from null.
const cases: { name: keyof typeof zod.schemas; valid: unknown[]; invalid: unknown[] }[] = [
    {
        name: 'integer',
        valid: [-9007199254740991, -7, 0, 9007199254740991],
        invalid: [-9007199254740992, 9007199254740992, 2.5, NaN, Infinity, -Infinity, '7', null],
    },
    { name: 'number', valid: [-2.5, 0, 7.25], invalid: [NaN, Infinity, -Infinity, '2.5', null] },
    { name: 'boolean', valid: [true, false], invalid: [0, 1, 'false', null] },
    { name: 'nullValue', valid: [null], invalid: [undefined, false, {}] },
    { name: 'voidValue', valid: [undefined], invalid: [null, false, {}] },
    { name: 'empty', valid: [{}], invalid: [{ extra: true }, [], 'text', 7, false, null, undefined] },
    {
        name: 'fields',
        valid: [
            fields,
            { ...fields, nullable: 'yes' },
            { ...fields, optional: undefined, both: undefined },
            { ...fields, optional: 'yes', both: null },
        ],
        invalid: [
            {},
            { required: 'yes' },
            { nullable: null },
            { ...fields, required: undefined },
            { ...fields, nullable: undefined },
            { ...fields, optional: null },
            { ...fields, extra: true },
        ],
    },
    { name: 'text', valid: ['ab', 'abc', '😀a', '😀😀😀'], invalid: ['', 'a', '😀', 'abcd', '😀😀😀😀', 7] },
    { name: 'boundedNumber', valid: [2, 3.5, 7], invalid: [1.999, 7.001, Infinity, '3'] },
    {
        name: 'uuid',
        valid: [
            uuid,
            uuid.toUpperCase(),
            '00000000-0000-0000-0000-000000000000',
            'ffffffff-ffff-ffff-ffff-ffffffffffff',
        ],
        invalid: [
            'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF',
            '0193b4ec-7050-0a89-8bcb-3a3397a63781',
            '0193b4ec-7050-7a89-7bcb-3a3397a63781',
            'not-a-uuid',
        ],
    },
    {
        name: 'ulid',
        valid: ['01ARZ3NDEKTSV4RRFFQ69G5FAV', '7ZZZZZZZZZZZZZZZZZZZZZZZZZ'],
        invalid: ['8ZZZZZZZZZZZZZZZZZZZZZZZZZ', '01ARZ3NDEKTSV4RRFFQ69G5FAI', '01ARZ3NDEKTSV4RRFFQ69G5FA'],
    },
    { name: 'date', valid: [date, new Date('0000-01-01T00:00:00.000Z')], invalid: [new Date(NaN), instant, 0, null] },
    {
        name: 'dateOnly',
        valid: [date, new Date('0000-01-01T00:00:00.000Z'), new Date('9999-12-31T23:59:59.999Z')],
        invalid: [
            new Date(NaN),
            new Date('-000001-12-31T00:00:00.000Z'),
            new Date('+010000-01-01T00:00:00.000Z'),
            '2024-02-29',
            null,
        ],
    },
    {
        name: 'dateWire',
        valid: [instant, '0000-02-29T00:00:00.000Z', '2000-02-29T00:00:00.000Z', '9999-12-31T23:59:59.999Z'],
        invalid: [
            date,
            '2023-02-29T00:00:00.000Z',
            '1900-02-29T00:00:00.000Z',
            '2024-04-31T00:00:00.000Z',
            '2024-02-29T24:00:00.000Z',
            '2024-02-29T12:34:60.000Z',
            '2024-02-29T12:34:56Z',
            '2024-02-29T12:34:56.12Z',
            '2024-02-29T12:34:56.1234Z',
            '2024-02-29T12:34:56.123+00:00',
            '+010000-01-01T00:00:00.000Z',
            '2024-02-29',
            null,
        ],
    },
    {
        name: 'dateOnlyWire',
        valid: ['2024-02-29', '0000-02-29', '2000-02-29', '9999-12-31'],
        invalid: [
            '2023-02-29',
            '1900-02-29',
            '2024-04-31',
            '2024-00-01',
            '2024-13-01',
            '2024-01-00',
            '2024-2-09',
            '10000-01-01',
            instant,
            date,
            null,
        ],
    },
    { name: 'tuple', valid: [['a', 7]], invalid: [[7, 'a'], ['a'], ['a', 7, 9], ['a', 2.5], { 0: 'a', 1: 7 }] },
    { name: 'list', valid: [['a'], ['a', 'b']], invalid: [[], ['a', 'b', 'c'], ['a', 7], { 0: 'a' }] },
    { name: 'union', valid: ['a', 7], invalid: [2.5, true, null, []] },
    { name: 'choices', valid: [7], invalid: [2, 3, '7', 7.5, null] },
    {
        name: 'draft',
        valid: [
            draft,
            { ...draft, at: null, priority: 2, reminder: null },
            { ...draft, priority: 7, reminder: date },
            { ...draft, priority: undefined, reminder: undefined },
        ],
        invalid: [
            { ...draft, extra: true },
            { ...draft, items: [{ at: date, extra: true }] },
            { ...draft, items: [{ at: new Date(NaN) }] },
            { ...draft, at: undefined },
            { ...draft, priority: 3 },
            wireDraft,
        ],
    },
    {
        name: 'wireDraft',
        valid: [
            wireDraft,
            { ...wireDraft, at: null, priority: 7, reminder: null },
            { ...wireDraft, reminder: instant },
        ],
        invalid: [
            draft,
            { ...wireDraft, items: [{ at: '2023-02-29T00:00:00.000Z' }] },
            { ...wireDraft, items: [{ at: instant, extra: true }] },
            { ...wireDraft, day: '2023-02-29' },
        ],
    },
    {
        name: 'routed',
        valid: [
            routed,
            { direction: 'desc', priority: 7 },
            {
                ...routed,
                __route: '/tasks',
                __routeArgs: { task: '0', count: -7 },
                __window: 1,
                __generation: uuid,
                __cursor: { title: null, id: 'a' },
            },
            { ...routed, __window: 2147483647, __cursor: { title: 'z', id: 'b' } },
        ],
        invalid: [
            {},
            { ...routed, direction: 'other' },
            { ...routed, priority: '2' },
            { ...routed, priority: 3 },
            { ...routed, __route: 7 },
            { ...routed, __routeArgs: { count: 2.5 } },
            { ...routed, __routeArgs: { task: null } },
            { ...routed, __window: 0 },
            { ...routed, __window: 2147483648 },
            { ...routed, __window: 2.5 },
            { ...routed, __generation: 'bad' },
            { ...routed, __cursor: { title: null } },
            { ...routed, __cursor: { title: null, id: 'a', extra: true } },
            { ...routed, extra: true },
        ],
    },
    {
        name: 'publicQuery',
        valid: [routed, { ...routed, __route: '/tasks', __routeArgs: {}, __window: 3 }],
        invalid: [
            { ...routed, __generation: uuid },
            { ...routed, __cursor: { title: null, id: 'a' } },
        ],
    },
    {
        name: 'scalars',
        valid: [scalars, { text: '', active: true, score: -2.5, limit: 9007199254740991 }],
        invalid: [
            { ...scalars, text: 0 },
            { ...scalars, active: 0 },
            { ...scalars, score: Infinity },
            { ...scalars, limit: 2.5 },
            { ...scalars, limit: 9007199254740992 },
        ],
    },
    {
        name: 'internalOption',
        valid: [{}, { __generation: uuid }],
        invalid: [{ __route: '/tasks' }, { __routeArgs: {} }, { __window: 1 }, { __generation: 'bad' }],
    },
    { name: 'publicOption', valid: [{}], invalid: [{ __generation: uuid }, { __window: 1 }, 'text', []] },
    {
        name: 'windowOption',
        valid: [{}, { __window: 1 }, { __window: 2147483647, __generation: uuid }],
        invalid: [{ __window: 0 }, { __window: 2147483648 }, { __route: '/tasks' }],
    },
    {
        name: 'requestPublic',
        valid: [{ input: { after: date } }],
        invalid: [
            { input: { after: instant } },
            { input: { after: new Date(NaN) } },
            { input: { after: date, extra: true } },
        ],
    },
    {
        name: 'requestWire',
        valid: [{ input: { after: instant } }],
        invalid: [{ input: { after: date } }, { input: { after: '2023-02-29T00:00:00.000Z' } }],
    },
    {
        name: 'contractQuery',
        valid: [
            { input: {} },
            { input: { direction: null, priority: 2, status: 'active' } },
            { input: { direction: 'desc', priority: 7, status: 'completed' } },
        ],
        invalid: [
            { input: 'not-the-override' },
            { input: { priority: 3 } },
            { input: { direction: 'other' } },
            { input: { status: 'other' } },
            { input: { priority: null } },
            { input: { extra: true } },
        ],
    },
];

describe.each([
    { name: 'Zod', generated: zod },
    { name: 'Effect', generated: effect },
])('$name generated contracts', ({ generated }) => {
    it.each(cases)(
        '$name preserves values and rejects invalid inputs through both synchronous adapters',
        ({ name, valid, invalid }) => {
            const schema = generated.schemas[name];
            for (const input of valid) {
                expect(schema.parse(input)).toStrictEqual(input);
                const result = schema['~standard'].validate(input);
                expect(result).not.toBeInstanceOf(Promise);
                expect(result).toStrictEqual({ value: input });
            }
            for (const input of invalid) {
                expect(() => schema.parse(input)).toThrow();
                const result = schema['~standard'].validate(input);
                expect(result).not.toBeInstanceOf(Promise);
                expect(result).toMatchObject({ issues: expect.any(Array) });
                if (result instanceof Promise) throw new Error('Zero requires synchronous validation');
                expect(result.issues?.length).toBeGreaterThan(0);
            }
        },
    );

    it('reports all nested error paths to both Standard Schema and the Formbraid adapter', () => {
        const input = { ...draft, title: 7, items: [{ at: new Date(NaN) }] };
        const standard = generated.schemas.draft['~standard'].validate(input);
        if (standard instanceof Promise) throw new Error('Zero requires synchronous validation');
        expect(validationFields(standard)).toEqual({ title: [expect.any(String)], 'items.0.at': [expect.any(String)] });
        let failure: unknown;
        try {
            generated.schemas.draft.parse(input);
        } catch (error) {
            failure = error;
        }
        expect(failure).toBeInstanceOf(Error);
        expect(validationFields(failure)).toEqual({ title: [expect.any(String)], 'items.0.at': [expect.any(String)] });
    });

    it.each([
        {
            name: 'nested dates and omission',
            input: { ...draft, priority: undefined, reminder: undefined },
            expected: wireDraft,
        },
        {
            name: 'explicit nulls',
            input: { ...draft, at: null, priority: 7 as const, reminder: null },
            expected: { ...wireDraft, at: null, priority: 7, reminder: null },
        },
        {
            name: 'date values',
            input: { ...draft, priority: 2 as const, reminder: date },
            expected: { ...wireDraft, priority: 2, reminder: instant },
        },
    ])('encodes $name without changing the public input', ({ input, expected }) => {
        const before = structuredClone(input);
        const encoded = generated.encodeDraft(input);
        expect(encoded).toStrictEqual(expected);
        expect(generated.schemas.wireDraft.parse(encoded)).toStrictEqual(expected);
        expect(input).toStrictEqual(before);
    });

    it('encodes public query dates while preserving route selection', () => {
        const input = { input: { after: date }, __route: '/dated', __routeArgs: { task: '0' }, __window: 3 };
        const expected = { ...input, input: { after: instant } };
        expect(generated.encodeQuery(input)).toStrictEqual(expected);
        expect(generated.schemas.requestWire.parse(expected)).toStrictEqual(expected);
        expect(input.input.after).toBe(date);
    });
});

it('retains native Effect composition, service-free decoding, and generated input inference', () => {
    const composed = EffectSchema.Struct({ draft: effect.schemas.draft });
    const form = defineForm<effect.DraftInput>()({
        id: 'SchemaParity',
        defaultValues: draft,
        schema: effect.schemas.draft,
        fields: {},
        groups: {},
    });
    expect(form.schema).toBe(effect.schemas.draft);
    expectTypeOf<typeof composed.DecodingServices>().toEqualTypeOf<never>();
    // These assignments must accept omitted optional fields, not just `undefined` unions.
    expectTypeOf<typeof draft>().toExtend<effect.DraftInput>();
    expectTypeOf<typeof draft>().toExtend<zod.DraftInput>();
    expectTypeOf<Omit<effect.DraftInput, 'at'>>().not.toExtend<effect.DraftInput>();
    expectTypeOf<Omit<zod.DraftInput, 'at'>>().not.toExtend<zod.DraftInput>();
    expectTypeOf<effect.DraftInput['priority']>().toEqualTypeOf<2 | 7 | undefined>();
    expectTypeOf<zod.DraftInput['priority']>().toEqualTypeOf<2 | 7 | undefined>();
    expectTypeOf<effect.DraftInput['at']>().toEqualTypeOf<Date | null>();
    expectTypeOf<zod.DraftInput['at']>().toEqualTypeOf<Date | null>();
    expectTypeOf<effect.QueryInput['input']['after']>().toEqualTypeOf<Date>();
    expectTypeOf<zod.QueryInput['input']['after']>().toEqualTypeOf<Date>();
    expectTypeOf<ReturnType<typeof effect.schemas.routed.parse>['direction']>().toEqualTypeOf<'asc' | 'desc'>();
    expectTypeOf<ReturnType<typeof zod.schemas.routed.parse>['direction']>().toEqualTypeOf<'asc' | 'desc'>();
    expect(EffectSchema.decodeUnknownSync(composed, { onExcessProperty: 'error' })({ draft })).toStrictEqual({ draft });
    expect(() =>
        EffectSchema.decodeUnknownSync(composed, { onExcessProperty: 'error' })({ draft: { ...draft, extra: true } }),
    ).toThrow();
});
