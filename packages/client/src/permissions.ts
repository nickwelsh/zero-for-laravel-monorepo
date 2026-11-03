/** Advisory UI predictions only. Always authorize mutations and reads on the server. */
export type PermissionScalar = string | number | boolean;
export type PermissionAttributeType = 'id' | 'string' | 'boolean' | 'integer';
export type PermissionComparison = {
    column: string;
    operator: '=' | '!=' | '<' | '<=' | '>' | '>=';
    value: PermissionScalar;
};
export type PermissionBranch = {
    resource: PermissionComparison[];
    grant: PermissionComparison[];
    correlations: Record<string, string>;
};
export type PermissionPredictionPlan = {
    model: string;
    type: string;
    action: string;
    denyOverride: boolean;
    admin: string | null;
    constant: boolean | null;
    subjectAttributes: Record<string, PermissionAttributeType>;
    resourceFields: Record<
        string,
        { column: string; type: PermissionAttributeType; prefix: string } | { value: string }
    >;
    branches: PermissionBranch[];
    grants: { effect: 'allow' | 'deny'; values: Record<string, PermissionScalar> }[];
};
export type PermissionPredictions = {
    version: 1;
    subject: { id: string; key: string; attributes: Record<string, PermissionScalar> };
    resources: Record<string, Record<string, PermissionPredictionPlan>>;
};

function record(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function own(value: Record<string, unknown>, name: string): unknown {
    return Object.hasOwn(value, name) ? value[name] : undefined;
}

function invalid(): never {
    throw new Error('Unavailable permission prediction');
}

function scalar(value: unknown, type: unknown): PermissionScalar {
    if (type === 'id') {
        if (typeof value === 'number' && Number.isSafeInteger(value)) value = String(value);
        if (typeof value === 'string' && value !== '' && new TextEncoder().encode(value).length <= 200) return value;
    } else if (type === 'string' && typeof value === 'string') {
        return value;
    } else if (type === 'boolean' && typeof value === 'boolean') {
        return value;
    } else if (type === 'integer' && typeof value === 'number' && Number.isSafeInteger(value)) {
        return value;
    }
    return invalid();
}

function grantType(column: string, subjectTypes: Record<string, unknown>): unknown {
    if (/^v[0-5]$/.test(column)) return 'string';
    if (column.startsWith('s_')) return own(subjectTypes, column.slice(2));
    return invalid();
}

function comparisons(input: unknown, types: Record<string, unknown>, grant: boolean): PermissionComparison[] {
    if (!Array.isArray(input)) return invalid();
    return input.map((item: unknown) => {
        if (!record(item) || typeof item.column !== 'string') return invalid();
        const type = grant ? grantType(item.column, types) : own(types, item.column);
        const value = scalar(item.value, type === 'id' ? 'string' : type);
        if (typeof item.operator !== 'string' || !['=', '!=', '<', '<=', '>', '>='].includes(item.operator))
            return invalid();
        if (item.operator !== '=' && item.operator !== '!=' && type !== 'integer') return invalid();
        return { column: item.column, operator: item.operator as PermissionComparison['operator'], value };
    });
}

function matches(values: Record<string, PermissionScalar>, conditions: PermissionComparison[]): boolean {
    return conditions.every(({ column, operator, value }) => {
        const actual = values[column];
        if (actual === undefined || typeof actual !== typeof value) return invalid();
        switch (operator) {
            case '=':
                return actual === value;
            case '!=':
                return actual !== value;
            case '<':
                return (actual as number) < (value as number);
            case '<=':
                return (actual as number) <= (value as number);
            case '>':
                return (actual as number) > (value as number);
            case '>=':
                return (actual as number) >= (value as number);
            default:
                return invalid();
        }
    });
}

/** Missing or wrong-identity feeds, omitted abilities and incomplete rows stay pending. */
export function predictPermission(
    feed: unknown,
    resource: string,
    ability: string,
    row: Readonly<Record<string, unknown>>,
    subjectId: string,
): boolean | undefined {
    try {
        if (!record(feed) || feed.version !== 1 || !record(feed.subject) || !record(feed.resources)) return undefined;
        if (typeof feed.subject.id !== 'string' || typeof feed.subject.key !== 'string') return undefined;
        if (feed.subject.id !== subjectId) return undefined;
        scalar(feed.subject.id, 'id');
        if (!record(feed.subject.attributes)) return undefined;
        const resourcePlans = own(feed.resources, resource);
        if (!record(resourcePlans)) return undefined;
        const plan = own(resourcePlans, ability);
        if (
            !record(plan) ||
            typeof plan.model !== 'string' ||
            typeof plan.type !== 'string' ||
            typeof plan.action !== 'string'
        )
            return undefined;
        if (typeof plan.denyOverride !== 'boolean' || (plan.constant !== null && typeof plan.constant !== 'boolean'))
            return undefined;
        if (plan.admin !== null && typeof plan.admin !== 'string') return undefined;
        if (
            !record(plan.subjectAttributes) ||
            !record(plan.resourceFields) ||
            !Array.isArray(plan.branches) ||
            !Array.isArray(plan.grants)
        )
            return undefined;

        const subject: Record<string, PermissionScalar> = Object.create(null);
        for (const [name, type] of Object.entries(plan.subjectAttributes)) {
            subject[name] = scalar(own(feed.subject.attributes, name), type);
        }
        if (plan.admin !== null && own(plan.subjectAttributes, plan.admin) !== 'boolean') return undefined;

        const values: Record<string, PermissionScalar> = Object.create(null);
        const types: Record<string, unknown> = Object.create(null);
        for (const [name, field] of Object.entries(plan.resourceFields)) {
            if (!record(field) || !/^(?:domain_key|object_id|a_[a-zA-Z_]\w*)$/.test(name)) return undefined;
            if (Object.hasOwn(field, 'value')) {
                if (name !== 'domain_key' || field.value !== '__global__') return undefined;
                values[name] = field.value;
                types[name] = 'string';
            } else {
                if (typeof field.column !== 'string' || typeof field.prefix !== 'string') return undefined;
                if (field.type !== 'id' && field.prefix !== '') return undefined;
                const value = scalar(own(row, field.column), field.type);
                values[name] = field.type === 'id' ? field.prefix + String(value) : value;
                types[name] = field.type;
            }
        }

        const branches = plan.branches.map((branch: unknown) => {
            if (!record(branch) || !record(branch.correlations)) return invalid();
            const resourceConditions = comparisons(branch.resource, types, false);
            const grantConditions = comparisons(branch.grant, plan.subjectAttributes as Record<string, unknown>, true);
            const correlations = Object.entries(branch.correlations).map(([column, target]) => {
                if (typeof target !== 'string' || !Object.hasOwn(types, column)) return invalid();
                const a = types[column] === 'id' ? 'string' : types[column];
                const b = grantType(target, plan.subjectAttributes as Record<string, unknown>);
                if (a !== (b === 'id' ? 'string' : b)) return invalid();
                return [column, target] as const;
            });
            return { resourceConditions, grantConditions, correlations };
        });
        const grants = plan.grants.map((grant: unknown) => {
            if (!record(grant) || !record(grant.values) || (grant.effect !== 'allow' && grant.effect !== 'deny'))
                return invalid();
            const fields: Record<string, PermissionScalar> = Object.create(null);
            for (const [name, value] of Object.entries(grant.values)) {
                fields[name] = scalar(value, grantType(name, plan.subjectAttributes as Record<string, unknown>));
                if (name.startsWith('s_') && fields[name] !== subject[name.slice(2)]) return invalid();
            }
            return { effect: grant.effect, fields };
        });

        if (plan.admin !== null && subject[plan.admin] === true) return true;
        if (plan.constant !== null) return plan.constant;
        const allows = (effect: string) =>
            grants.some(
                (grant) =>
                    grant.effect === effect &&
                    branches.some(
                        (branch) =>
                            matches(values, branch.resourceConditions) &&
                            matches(grant.fields, branch.grantConditions) &&
                            branch.correlations.every(([a, b]) => {
                                if (!Object.hasOwn(grant.fields, b)) return invalid();
                                return values[a] === grant.fields[b];
                            }),
                    ),
            );
        return allows('allow') && (!plan.denyOverride || !allows('deny'));
    } catch {
        return undefined;
    }
}
