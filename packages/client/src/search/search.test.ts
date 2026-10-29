import { describe, expect, it } from 'vite-plus/test';
import { createSearchGrammar, rankSearchRows } from './index';
const metadata = {
    index: 'records',
    key: 'id',
    queryBy: 'title',
    fields: { title: 'string', tags: 'string[]', done: 'bool', created_at: 'int64' },
    facets: ['tags'],
    sorts: ['created_at:desc'],
} as const;
const grammar = createSearchGrammar(metadata, {
    aliases: { label: 'tags' },
    values: { 'is:done': { field: 'done', value: true } },
    dates: { after: { field: 'created_at', operator: '>=' } },
});
describe('Scout grammar', () => {
    it('keeps ordinary text in the search engine and compiles typed refinements', () => {
        expect(grammar.parse('read this')).toEqual({ query: 'read this', filter: '' });
        expect(grammar.parse('label:work is:done').filter).toBe('(tags:=`work` && done:=true)');
        expect(grammar.parse('-label:work').filter).toBe('tags:!=`work`');
        expect(grammar.parse('after:2026/10/23').filter).toContain('created_at:>=');
    });
    it('fails closed for unknown fields and syntax injection', () => {
        expect(grammar.parse('owner:alice').error).toBeDefined();
        expect(grammar.parse('label:"x` || owner:>0"').error).toBeDefined();
        expect(grammar.parse('done:maybe').error).toBeDefined();
    });
    it('derives suggestions and preserves rank without fabricating missing records', () => {
        expect(grammar.suggestions('la', 2).some((item) => item.value === 'label:')).toBe(true);
        expect(rankSearchRows(['b', 'a', 'missing'], [{ id: 'a' }, { id: 'b' }], (row) => row.id)).toEqual([
            { id: 'b' },
            { id: 'a' },
        ]);
    });
});
