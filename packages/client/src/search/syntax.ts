export type SearchExpression =
    | {
          kind: 'cmp';
          field: string;
          op: '=' | '!=' | 'ILIKE' | '<' | '>=' | 'IS NOT';
          value: string | number | boolean | null;
      }
    | { kind: 'and'; children: Array<SearchExpression> }
    | { kind: 'or'; children: Array<SearchExpression> }
    | { kind: 'not'; child: SearchExpression };

export type SearchSyntax =
    | { kind: 'term'; operator: string; value: string }
    | { kind: 'and' | 'or'; children: Array<SearchSyntax> }
    | { kind: 'not'; child: SearchSyntax };
interface Token {
    value: string;
    quoted: boolean;
}

function tokenize(input: string): Array<Token> {
    const tokens: Array<Token> = [];
    const pattern = /\s+|(?<punct>[(){}-])|(?<quote>"(?:\\.|[^"\\])*")|(?<word>[^\s(){}"]+)/gu;
    let consumed = 0;
    for (const match of input.matchAll(pattern)) {
        if (match.index !== consumed) {
            throw new Error('Close the quote to search for this phrase.');
        }
        consumed = match.index + match[0].length;
        const groups = match.groups ?? {};
        const value = groups['punct'] ?? groups['word'] ?? groups['quote'];
        if (value !== undefined) {
            const quoted = groups['quote'] !== undefined;
            tokens.push({
                value: quoted ? value.slice(1, -1).replaceAll(/\\(?<escaped>.)/gu, '$<escaped>') : value,
                quoted,
            });
        }
    }
    if (consumed !== input.length) {
        throw new Error('Close the quote to search for this phrase.');
    }
    if (tokens.length > 64) {
        throw new Error('Try a shorter search (up to 64 terms).');
    }
    return tokens;
}

function syntaxGroup(kind: 'and' | 'or', children: Array<SearchSyntax>): SearchSyntax {
    const [first] = children;
    if (!first) {
        throw new Error('Add a search term to finish this query.');
    }
    return children.length === 1 ? first : { kind, children };
}

class Parser {
    private position = 0;
    public constructor(private readonly tokens: Array<Token>) {}
    public parse(): SearchSyntax {
        const result = this.group('', false, 0);
        if (this.position !== this.tokens.length) {
            throw new Error('Check the search grouping.');
        }
        return result;
    }
    private is(value: string): boolean {
        const token = this.tokens[this.position];
        return token?.quoted === false && token.value === value;
    }
    private group(operator: string, any: boolean, depth: number): SearchSyntax {
        if (depth > 8) {
            throw new Error('Try fewer nested search groups.');
        }
        const branches: Array<SearchSyntax> = [];
        let terms: Array<SearchSyntax> = [];
        while (this.position < this.tokens.length && !this.is(')') && !this.is('}')) {
            if (this.is('OR')) {
                if (terms.length === 0) {
                    throw new Error('Add a search term before OR.');
                }
                branches.push(syntaxGroup('and', terms));
                terms = [];
                this.position += 1;
            } else {
                if (this.is('AND')) {
                    if (terms.length === 0) {
                        throw new Error('Add a search term before AND.');
                    }
                    this.position += 1;
                    if (
                        !this.tokens[this.position] ||
                        this.is(')') ||
                        this.is('}') ||
                        this.is('OR') ||
                        this.is('AND')
                    ) {
                        throw new Error('Add a search term after AND.');
                    }
                }
                terms.push(this.term(operator, depth));
            }
        }
        if (terms.length === 0) {
            throw new Error('Add a search term to finish this query.');
        }
        if (any) {
            branches.push(...terms);
        } else {
            branches.push(syntaxGroup('and', terms));
        }
        return syntaxGroup('or', branches);
    }
    private term(inherited: string, depth: number): SearchSyntax {
        if (this.is('-')) {
            this.position += 1;
            return { kind: 'not', child: this.term(inherited, depth + 1) };
        }
        let token = this.tokens[this.position];
        this.position += 1;
        if (!token) {
            throw new Error('Add a search term after the minus sign.');
        }
        let operator = inherited;
        if (!token.quoted && token.value.includes(':')) {
            const colon = token.value.indexOf(':');
            operator = token.value.slice(0, colon).toLowerCase();
            const value = token.value.slice(colon + 1);
            if (value) {
                token = { value, quoted: false };
            } else {
                token = this.tokens[this.position];
                this.position += 1;
            }
            if (!token || (!token.quoted && ['AND', 'OR', ')', '}'].includes(token.value))) {
                throw new Error(`Add a value after ${operator}:`);
            }
        }
        if (!token.quoted && ['(', '{'].includes(token.value)) {
            const close = token.value === '(' ? ')' : '}';
            const child = this.group(operator, close === '}', depth + 1);
            if (!this.is(close)) {
                throw new Error(`Close this group with ${close}`);
            }
            this.position += 1;
            return child;
        }
        if (!token.value) {
            throw new Error('Add a word inside the quotes.');
        }
        return { kind: 'term', operator, value: token.value };
    }
}

export function expressionSize(node: SearchExpression, depth = 0): number {
    if (depth > 16) {
        throw new Error('Try fewer nested search groups.');
    }
    if (node.kind === 'not') {
        return 1 + expressionSize(node.child, depth + 1);
    }
    if (node.kind === 'cmp') {
        return 1;
    }
    return 1 + node.children.reduce((count, child) => count + expressionSize(child, depth + 1), 0);
}

export function parseSearchSyntax(input: string): SearchSyntax {
    return new Parser(tokenize(input)).parse();
}
export function dateValue(value: string): number {
    if (/^\d{10}$/u.test(value)) {
        return Number(value) * 1000;
    }
    const parts =
        /^(?<year>\d{4})[-/](?<month>\d{1,2})[-/](?<day>\d{1,2})$/u.exec(value)?.groups ??
        /^(?<month>\d{1,2})\/(?<day>\d{1,2})\/(?<year>\d{4})$/u.exec(value)?.groups;
    const year = Number(parts?.['year']);
    const month = Number(parts?.['month']);
    const day = Number(parts?.['day']);
    const date = new Date(year, month - 1, day);
    if (
        !Number.isFinite(date.getTime()) ||
        date.getFullYear() !== year ||
        date.getMonth() !== month - 1 ||
        date.getDate() !== day
    ) {
        throw new Error('Use a valid date, such as 2026/10/22.');
    }
    return date.getTime();
}

export function relativeDate(value: string, now: Date): number {
    const match = /^(?<count>\d+)(?<unit>[dmy])$/iu.exec(value)?.groups;
    if (!match || Number(match['count']) > 10_000) {
        throw new Error('Use a duration such as 7d, 2m, or 1y.');
    }
    const date = new Date(now);
    const count = Number(match['count']);
    switch ((match['unit'] ?? '').toLowerCase()) {
        case 'd': {
            date.setDate(date.getDate() - count);
            break;
        }
        case 'm': {
            date.setMonth(date.getMonth() - count);
            break;
        }
        default: {
            date.setFullYear(date.getFullYear() - count);
        }
    }
    return date.getTime();
}

export function mapSearchSyntax(
    node: SearchSyntax,
    term: (value: Extract<SearchSyntax, { kind: 'term' }>) => SearchExpression,
): SearchExpression {
    if (node.kind === 'term') {
        return term(node);
    }
    if (node.kind === 'not') {
        return { kind: 'not', child: mapSearchSyntax(node.child, term) };
    }
    return { kind: node.kind, children: node.children.map((child) => mapSearchSyntax(child, term)) };
}
