import { access, readFile } from 'node:fs/promises';
import { registerHooks } from 'node:module';

const root = new URL('../packages/client/', import.meta.url);
const manifest = JSON.parse(await readFile(new URL('package.json', root), 'utf8'));
for (const entry of Object.values(manifest.exports)) {
    await access(new URL(entry.types, root));
    await access(new URL(entry.import, root));
}
await access(new URL('LICENSE', root));
await access(new URL('README.md', root));
registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier === 'react' || specifier.startsWith('react/')) {
            throw new Error('The core package must load without React.');
        }
        if (specifier.startsWith('@formbraid/')) {
            throw new Error('Formbraid must remain a type-only dependency.');
        }
        return nextResolve(specifier, context);
    },
});
const core = await import(new URL('dist/index.js', root).href);
if (typeof core.createZeroForLaravel !== 'function') throw new Error('Missing core runtime export.');
console.log('Built exports exist, and the core package loads without React.');

// Optional integration peers must stay optional in both installation metadata and declarations.
if (manifest.peerDependenciesMeta?.['@formbraid/core']?.optional !== true)
    throw new Error('FormBraid must be an optional peer.');
const { readdir } = await import('node:fs/promises');
for (const file of await readdir(new URL('dist/', root), { recursive: true })) {
    if (file.endsWith('.d.ts') && (await readFile(new URL(`dist/${file}`, root), 'utf8')).includes('@formbraid/'))
        throw new Error(`Query-only declarations depend on FormBraid: ${file}`);
}
