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
let allowReact = false;
registerHooks({
    resolve(specifier, context, nextResolve) {
        if (!allowReact && (specifier === 'react' || specifier.startsWith('react/'))) {
            throw new Error('The core package must load without React.');
        }
        if (specifier.startsWith('@formbraid/') || specifier === 'effect' || specifier.startsWith('effect/')) {
            throw new Error('Zero bindings must load without the optional Formbraid runtime.');
        }
        return nextResolve(specifier, context);
    },
});
const core = await import(new URL('dist/index.js', root).href);
if (typeof core.createControllerMutations !== 'function' || typeof core.liveQuery !== 'function')
    throw new Error('Missing controller runtime exports.');
const forms = await import(new URL('dist/formbraid.js', root).href);
if (typeof forms.createControllerForm !== 'function') throw new Error('Missing Formbraid descriptor producer.');
allowReact = true;
await import(new URL('dist/react/index.js', root).href);
console.log(
    'Built exports exist; core and form producers load without React; all bindings load without Formbraid or Effect.',
);
