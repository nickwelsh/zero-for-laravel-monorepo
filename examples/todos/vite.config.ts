import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            command: 'php artisan zero:generate --react && php artisan wayfinder:generate',
            patterns: ['routes/**/*.php', 'app/**/*.php', 'config/wayfinder.php'],
        }),
    ]),
    resolve: { dedupe: ['react', 'react-dom', '@rocicorp/zero'] },
    server: {
        watch: {
            ignored: ['**/.agents/**', '**/.claude/**', '**/.cursor/**', '**/.junie/**', '**/vendor/**'],
        },
    },
});
