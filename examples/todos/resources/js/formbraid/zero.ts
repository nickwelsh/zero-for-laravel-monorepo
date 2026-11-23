import type { Integration } from '@formbraid/core/vite';

export default {
    name: 'zero',
    registry: new URL('./index.ts', import.meta.url),
    metadata: new URL('./_metadata.json', import.meta.url),
    command: ['pnpm', 'zero:contracts'],
    watch: ['app/**/*.php', 'routes/**/*.php', 'config/zero.php', 'config/formbraid.php', 'config/wayfinder.php'],
    outputs: ['resources/js/zero/generated/**', 'resources/js/wayfinder/**', 'app/Formbraid/FormField.php'],
} satisfies Integration;
