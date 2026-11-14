import {
    groupId,
    queryKey,
    readMetadata,
    type Capabilities,
    type GroupPath,
    type MetadataResponse,
    type Scalar,
    type TableState,
} from '../index';

function record(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
function scalar(value: unknown): value is Scalar {
    return (
        value === null ||
        typeof value === 'string' ||
        typeof value === 'boolean' ||
        (typeof value === 'number' && Number.isFinite(value))
    );
}
function count(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}
function invalid(kind = 'metadata'): never {
    throw new Error(`Invalid table ${kind} response.`);
}

export async function readTableMetadata<C extends Capabilities>(
    capabilities: C,
    state: TableState<C>,
    directories: readonly { path: GroupPath; after: string | null }[],
    signal: AbortSignal,
): Promise<MetadataResponse> {
    const result: unknown = await readMetadata(capabilities, state, directories, signal);
    if (
        !record(result) ||
        result.version !== 2 ||
        typeof result.requestKey !== 'string' ||
        typeof result.observedAt !== 'string' ||
        !count(result.totalCount) ||
        !Array.isArray(result.directories) ||
        result.directories.length !== directories.length
    )
        invalid();
    for (const [index, directory] of directories.entries()) {
        const page = result.directories[index]!;
        if (
            !record(page) ||
            !Array.isArray(page.path) ||
            !page.path.every((part: unknown) => record(part) && typeof part.field === 'string' && scalar(part.value)) ||
            !Array.isArray(page.groups) ||
            page.groups.length > 50 ||
            (page.nextCursor !== null && typeof page.nextCursor !== 'string')
        )
            invalid();
        if (queryKey(page.path) !== queryKey(directory.path)) invalid('directory');
        const field = state.groupBy[directory.path.length]!.field;
        for (const group of page.groups) {
            if (
                !record(group) ||
                typeof group.id !== 'string' ||
                !scalar(group.value) ||
                typeof group.label !== 'string' ||
                !count(group.count)
            )
                invalid();
            if (group.id !== groupId([...directory.path, { field, value: group.value }])) invalid('group');
        }
    }
    return result as unknown as MetadataResponse;
}
