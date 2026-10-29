export interface SearchSuggestion {
    id: string;
    label: string;
    description: string;
    value: string;
}

export function completeSearchToken(
    input: string,
    caret: number,
    choicesFor: (fragment: string) => ReadonlyArray<readonly [string, string]>,
): Array<SearchSuggestion> {
    const before = input.slice(0, caret);
    if (before.replaceAll(/\\./gu, '').split('"').length % 2 === 0) {
        return [];
    }
    const match = /(?:^|[\s({])(?<negative>-?)(?<fragment>[^\s(){}"]*)$/u.exec(before);
    if (!match) {
        return [];
    }
    const negative = match.groups?.['negative'] ?? '';
    const fragment = match.groups?.['fragment'] ?? '';
    const start = caret - fragment.length;
    const tail = /^[^\s(){}"]*/u.exec(input.slice(caret))?.[0].length ?? 0;
    const choices = choicesFor(fragment);
    const prefix = fragment.toLowerCase();
    return choices
        .filter(([label]) => label.replaceAll('"', '').toLowerCase().startsWith(prefix) && label !== fragment)
        .slice(0, 7)
        .map(([label, description]) => ({
            id: label,
            label: `${negative}${label}`,
            description,
            value: `${input.slice(0, start)}${label}${label.endsWith(':') || /^\s/u.test(input.slice(caret + tail)) ? '' : ' '}${input.slice(caret + tail)}`,
        }));
}
