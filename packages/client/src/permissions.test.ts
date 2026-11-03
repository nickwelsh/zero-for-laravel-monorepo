import { expect, it } from 'vite-plus/test';
import { predictPermission } from './permissions';
import type { PermissionPredictions, PermissionPredictionPlan } from './permissions';

const resource = 'App\\Models\\TodoList';

function fixture(): { feed: PermissionPredictions; plan: PermissionPredictionPlan } {
    const common = [
        { column: 'v2', operator: '=' as const, value: 'todo-list' },
        { column: 'v3', operator: '=' as const, value: 'update' },
    ];
    const plan: PermissionPredictionPlan = {
        model: 'default',
        type: 'todo-list',
        action: 'update',
        denyOverride: true,
        admin: null,
        constant: null,
        subjectAttributes: { department_id: 'string', is_admin: 'boolean' },
        resourceFields: {
            domain_key: { column: 'id', type: 'id', prefix: 'list:' },
            a_owner_id: { column: 'user_id', type: 'id', prefix: '' },
            a_department_id: { column: 'department_id', type: 'string', prefix: '' },
        },
        branches: [
            {
                resource: [],
                grant: [...common, { column: 'v4', operator: '=', value: 'any' }],
                correlations: { domain_key: 'v1' },
            },
            {
                resource: [{ column: 'a_owner_id', operator: '=', value: '42' }],
                grant: [...common, { column: 'v4', operator: '=', value: 'owner' }],
                correlations: { domain_key: 'v1' },
            },
            {
                resource: [],
                grant: [...common, { column: 'v4', operator: '=', value: 'department' }],
                correlations: { domain_key: 'v1', a_department_id: 's_department_id' },
            },
        ],
        grants: [],
    };
    return {
        plan,
        feed: {
            version: 1,
            subject: { id: '42', key: 'user:42', attributes: { department_id: 'engineering', is_admin: false } },
            resources: { [resource]: { update: plan } },
        },
    };
}

function grant(
    effect: 'allow' | 'deny',
    condition = 'any',
    domain = 'list:7',
): PermissionPredictionPlan['grants'][number] {
    return {
        effect,
        values: { v1: domain, v2: 'todo-list', v3: 'update', v4: condition, s_department_id: 'engineering' },
    };
}

const row = { id: 7, user_id: 42, department_id: 'engineering' };

it('leaves missing feeds, unapproved abilities and unloaded attributes pending', () => {
    const { feed } = fixture();
    expect(predictPermission(undefined, resource, 'update', row, '42')).toBeUndefined();
    expect(predictPermission(feed, resource, 'delete', row, '42')).toBeUndefined();
    expect(predictPermission(feed, 'Other', 'update', row, '42')).toBeUndefined();
    expect(predictPermission(feed, resource, 'update', {}, '42')).toBeUndefined();
    expect(predictPermission({ ...feed, version: 2 }, resource, 'update', row, '42')).toBeUndefined();
});

it('cannot reuse Alice’s persisted feed for Bob or coerce the current identity', () => {
    const { feed, plan } = fixture();
    plan.grants.push(grant('allow'));
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(true);
    expect(predictPermission(feed, resource, 'update', row, '84')).toBeUndefined();
    expect(predictPermission(feed, resource, 'update', row, '042')).toBeUndefined();
    for (const identity of [42, null, undefined, true]) {
        expect(predictPermission(feed, resource, 'update', row, identity as unknown as string)).toBeUndefined();
    }
    plan.admin = 'is_admin';
    feed.subject.attributes.is_admin = true;
    expect(predictPermission(feed, resource, 'update', row, '84')).toBeUndefined();
    plan.constant = true;
    expect(predictPermission(feed, resource, 'update', row, '84')).toBeUndefined();
});

it('requires an applicable grant even for owners and never evaluates another domain or action', () => {
    const { feed, plan } = fixture();
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(false);
    plan.grants.push(grant('allow', 'owner'));
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(true);
    expect(predictPermission(feed, resource, 'update', { ...row, user_id: 84 }, '42')).toBe(false);
    expect(predictPermission(feed, resource, 'update', { ...row, id: 8 }, '42')).toBe(false);
    plan.grants[0]!.values.v3 = 'delete';
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(false);
});

it('applies deny override only to matching branches and respects allow-only models', () => {
    const { feed, plan } = fixture();
    plan.grants.push(grant('allow'), grant('deny', 'owner'));
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(false);
    expect(predictPermission(feed, resource, 'update', { ...row, user_id: 84 }, '42')).toBe(true);
    plan.grants.push(grant('deny', 'any', 'list:8'));
    expect(predictPermission(feed, resource, 'update', { ...row, user_id: 84 }, '42')).toBe(true);
    plan.denyOverride = false;
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(true);
    plan.grants = [grant('deny')];
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(false);
});

it('correlates exposed ABAC attributes without case folding or implicit string conversion', () => {
    const { feed, plan } = fixture();
    plan.grants.push(grant('allow', 'department'));
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(true);
    expect(predictPermission(feed, resource, 'update', { ...row, department_id: 'Engineering' }, '42')).toBe(false);
    expect(predictPermission(feed, resource, 'update', { ...row, department_id: 42 }, '42')).toBeUndefined();
    plan.grants[0]!.values.s_department_id = 'other-subject';
    expect(predictPermission(feed, resource, 'update', row, '42')).toBeUndefined();
});

it('uses canonical string IDs while retaining significant zeros and refusing lossy or boolean coercion', () => {
    const { feed, plan } = fixture();
    plan.grants.push(grant('allow', 'owner'));
    expect(predictPermission(feed, resource, 'update', { ...row, id: '7', user_id: '42' }, '42')).toBe(true);
    expect(predictPermission(feed, resource, 'update', { ...row, id: '07' }, '42')).toBe(false);
    expect(predictPermission(feed, resource, 'update', { ...row, user_id: '042' }, '42')).toBe(false);
    for (const id of [
        true,
        false,
        null,
        undefined,
        7.5,
        NaN,
        Infinity,
        Number.MAX_SAFE_INTEGER + 1,
        '',
        'é'.repeat(101),
    ]) {
        expect(predictPermission(feed, resource, 'update', { ...row, id }, '42')).toBeUndefined();
    }
});

it.each([
    ['=', 3, true],
    ['!=', 3, false],
    ['<', 4, true],
    ['<=', 3, true],
    ['>', 2, true],
    ['>=', 3, true],
] as const)(
    'supports bounded integer comparison %s without SQL or JavaScript coercions',
    (operator, value, expected) => {
        const { feed, plan } = fixture();
        plan.resourceFields.a_rank = { column: 'rank', type: 'integer', prefix: '' };
        plan.branches = [{ resource: [{ column: 'a_rank', operator, value }], grant: [], correlations: {} }];
        plan.grants.push(grant('allow'));
        expect(predictPermission(feed, resource, 'update', { ...row, rank: 3 }, '42')).toBe(expected);
        for (const rank of ['3', true, 3.2, Number.MAX_SAFE_INTEGER + 1]) {
            expect(predictPermission(feed, resource, 'update', { ...row, rank }, '42')).toBeUndefined();
        }
    },
);

it('requires actual booleans for public boolean attributes', () => {
    const { feed, plan } = fixture();
    plan.resourceFields.a_done = { column: 'completed', type: 'boolean', prefix: '' };
    plan.branches = [{ resource: [{ column: 'a_done', operator: '=', value: true }], grant: [], correlations: {} }];
    plan.grants.push(grant('allow'));
    expect(predictPermission(feed, resource, 'update', { ...row, completed: true }, '42')).toBe(true);
    expect(predictPermission(feed, resource, 'update', { ...row, completed: false }, '42')).toBe(false);
    for (const completed of [1, 0, 'true', null]) {
        expect(predictPermission(feed, resource, 'update', { ...row, completed }, '42')).toBeUndefined();
    }
});

it('supports exposed admin overrides while false falls back to ordinary allow and deny matching', () => {
    const { feed, plan } = fixture();
    plan.admin = 'is_admin';
    plan.grants.push(grant('deny'));
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(false);
    feed.subject.attributes.is_admin = true;
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(true);
    feed.subject.attributes.is_admin = 'true';
    expect(predictPermission(feed, resource, 'update', row, '42')).toBeUndefined();
    delete feed.subject.attributes.is_admin;
    expect(predictPermission(feed, resource, 'update', row, '42')).toBeUndefined();
});

it('supports constant policies and global domains with no graph or grant expansion in the browser', () => {
    const { feed, plan } = fixture();
    plan.resourceFields = {};
    plan.subjectAttributes = {};
    plan.branches = [];
    plan.constant = false;
    expect(predictPermission(feed, resource, 'update', {}, '42')).toBe(false);
    plan.constant = true;
    expect(predictPermission(feed, resource, 'update', {}, '42')).toBe(true);
    plan.constant = null;
    plan.resourceFields.domain_key = { value: '__global__' };
    plan.branches = [{ resource: [], grant: [], correlations: { domain_key: 'v1' } }];
    plan.grants = [{ effect: 'allow', values: { v1: '__global__' } }];
    expect(predictPermission(feed, resource, 'update', {}, '42')).toBe(true);
});

it('leaves malformed or unsupported plans pending rather than running expressions or inherited properties', () => {
    const { feed, plan } = fixture();
    plan.grants.push(grant('allow'));
    const before = JSON.stringify(feed);
    expect(predictPermission(feed, resource, 'update', row, '42')).toBe(true);
    expect(JSON.stringify(feed)).toBe(before);
    expect(predictPermission(feed, resource, 'update', Object.create(row), '42')).toBeUndefined();
    expect(
        predictPermission({ ...feed, resources: Object.create(feed.resources) }, resource, 'update', row, '42'),
    ).toBeUndefined();
    plan.grants[0]!.values.rule_id = 'private-provenance';
    expect(predictPermission(feed, resource, 'update', row, '42')).toBeUndefined();
    delete plan.grants[0]!.values.rule_id;
    plan.branches[0]!.grant[0] = { column: 'v2', operator: 'eval' as '=', value: 'todo-list' };
    expect(predictPermission(feed, resource, 'update', row, '42')).toBeUndefined();
});
