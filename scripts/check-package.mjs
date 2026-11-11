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
let allowInertia = false;
registerHooks({
    resolve(specifier, context, nextResolve) {
        if (!allowInertia && specifier.startsWith('@inertiajs/'))
            throw new Error('Generic package entry points must load without Inertia.');
        if (
            !allowReact &&
            (specifier === 'react' || specifier.startsWith('react/') || specifier.startsWith('@tanstack/'))
        ) {
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
const tableRoot = new URL('../packages/table/', import.meta.url);
const tableManifest = JSON.parse(await readFile(new URL('package.json', tableRoot), 'utf8'));
for (const entry of Object.values(tableManifest.exports)) {
    await access(new URL(entry.types, tableRoot));
    await access(new URL(entry.import, tableRoot));
}
await access(new URL('LICENSE', tableRoot));
await access(new URL('README.md', tableRoot));
const table = await import(new URL('dist/index.js', tableRoot).href);
if (typeof table.createTable !== 'function') throw new Error('Missing React-free table descriptor producer.');
const forms = await import(new URL('dist/formbraid.js', root).href);
if (typeof forms.createControllerForm !== 'function') throw new Error('Missing Formbraid descriptor producer.');
allowReact = true;
await import(new URL('dist/react/index.js', tableRoot).href);
await import(new URL('dist/react/index.js', root).href);
const optionSources = await import(new URL('dist/react/formbraid.js', root).href);
if (typeof optionSources.createZeroOptionSource !== 'function') throw new Error('Missing Zero option source producer.');
const tableForms = await import(new URL('dist/formbraid.js', tableRoot).href);
if (typeof tableForms.formbraidSubmit !== 'function' || typeof tableForms.RelationEditor !== 'function')
    throw new Error('Missing optional table form bridge.');
allowInertia = true;
const tableInertia = await import(new URL('dist/inertia.js', tableRoot).href);
if (typeof tableInertia.useDataTable !== 'function') throw new Error('Missing packaged Inertia table hook.');
console.log(
    'Built exports exist; core and form producers load without React; generic entries load without Inertia; all bindings load without Formbraid or Effect.',
);
