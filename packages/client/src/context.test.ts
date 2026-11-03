import { expect, expectTypeOf, it } from 'vite-plus/test';
import { InputError, validateContext, type ClientContext, type ContextContract } from './index';

const contract = {
    tenant: { type: 'string', nullable: false },
    actor: { type: 'int', nullable: true },
    ratio: { type: 'float', nullable: false },
    enabled: { type: 'bool', nullable: false },
} as const satisfies ContextContract;
const valid = { tenant: 'team', actor: null, ratio: 0.5, enabled: true };

it('validates application-owned scalar context without a user_id convention', () => {
    expectTypeOf(valid).toExtend<ClientContext>();
    expect(() => validateContext(valid, contract)).not.toThrow();
    expect(() => validateContext({ ...valid, actor: Number.MAX_SAFE_INTEGER, ratio: 1 }, contract)).not.toThrow();
    expect(() => validateContext({ ...valid, actor: Number.MIN_SAFE_INTEGER }, contract)).not.toThrow();
    expect(() => validateContext({}, {})).not.toThrow();
    expect(() => validateContext(valid)).not.toThrow();
    const unknown: unknown = valid;
    validateContext(unknown, contract);
    expectTypeOf(unknown).toEqualTypeOf<ClientContext>();
});

it('requires every reflected key, including nullable keys, and rejects extra keys', () => {
    const { actor: _actor, ...missing } = valid;
    for (const value of [missing, { ...valid, actor: undefined }, { ...valid, user_id: 'unexpected' }])
        expect(() => validateContext(value, contract)).toThrow(InputError);
    expect(() => validateContext({ ...valid, [Symbol('extra')]: true }, contract)).toThrow(InputError);
    expect(() =>
        validateContext(Object.defineProperty({ ...valid }, 'actor', { enumerable: false }), contract),
    ).toThrow(InputError);
    expect(() => validateContext({ tenant: null }, { tenant: contract.tenant })).toThrow(InputError);
});

it('rejects wrong scalar types, unsafe integers, and nonfinite numbers without coercion', () => {
    for (const actor of [0.1, Number.MAX_SAFE_INTEGER + 1, Number.MIN_SAFE_INTEGER - 1, NaN, Infinity, '1', true])
        expect(() => validateContext({ ...valid, actor }, contract)).toThrow(InputError);
    for (const ratio of [NaN, Infinity, -Infinity, '0.5', false, null])
        expect(() => validateContext({ ...valid, ratio }, contract)).toThrow(InputError);
    for (const enabled of [0, 1, 'true', 'false', null])
        expect(() => validateContext({ ...valid, enabled }, contract)).toThrow(InputError);
    for (const tenant of [1, false, null, [], {}])
        expect(() => validateContext({ ...valid, tenant }, contract)).toThrow(InputError);
});

it('rejects nonscalar contexts even without a reflected contract', () => {
    for (const value of [null, [], new Date(), 'team', { nested: {} }, { value: undefined }, { value: NaN }])
        expect(() => validateContext(value)).toThrow(InputError);
    expect(() => validateContext(Object.create({ inherited: true }), {})).toThrow(InputError);
});
