import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const expected = execFileSync('php', ['tests/interop/export.php', 'generate'], {
    encoding: 'utf8',
});
if (expected !== readFileSync('tests/interop/generated.ts', 'utf8')) {
    throw new Error(
        'Generated contracts are stale. Run php tests/interop/export.php generate > tests/interop/generated.ts.',
    );
}
console.log('Generated contracts match the PHP model definitions.');

for (const [mode, path] of [
    ['mutations', 'packages/client/src/controller-mutation.fixture.ts'],
    ['assignment', 'tests/interop/assignment.generated.ts'],
    ['react', 'tests/interop/controller-react.generated.ts'],
]) {
    const generated = execFileSync('php', ['packages/laravel/tests/Fixtures/export-controller-mutations.php', mode], {
        encoding: 'utf8',
    });
    if (generated !== readFileSync(path, 'utf8')) {
        throw new Error(`Generated controller fixture is stale: ${path}`);
    }
}
console.log('Generated controller fixtures match their PHP actions.');
