import type { Integration } from '@formbraid/core/vite';

export default {
    name: 'zero',
    registry: new URL('./index.ts', import.meta.url),
    metadata: new URL('./_metadata.json', import.meta.url),
    command: ['php', 'artisan', 'zero:generate'],
    watch: ['app/**/*.php', 'routes/**/*.php', 'config/zero.php', 'config/formbraid.php'],
    outputs: ['resources/js/formbraid/zero/**', 'resources/js/zero/**', 'app/Formbraid/FormField.php'],
} satisfies Integration;
