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
        return nextResolve(specifier, context);
    },
});
const core = await import(new URL('dist/index.js', root).href);
if (typeof core.createControllerMutations !== 'function' || typeof core.liveQuery !== 'function')
    throw new Error('Missing controller runtime exports.');
console.log('Built exports exist, and the core package loads without React.');
