import { defineConfig } from 'vite-plus';

export default defineConfig({
    pack: {
        entry: { index: 'src/index.ts', 'react/index': 'src/react/index.tsx', formbraid: 'src/formbraid.tsx' },
        format: ['esm'],
        dts: true,
        clean: true,
        deps: {
            neverBundle: [
                '@formbraid/core',
                '@formbraid/react',
                '@nickwelsh/zero-for-laravel/react/formbraid',
                '@tanstack/react-virtual',
                '@nickwelsh/zero-for-laravel',
                '@nickwelsh/zero-for-laravel/react',
                '@rocicorp/zero',
                '@rocicorp/zero/react',
                'react',
                'react/jsx-runtime',
            ],
        },
        outExtensions: () => ({ js: '.js', dts: '.d.ts' }),
    },
});
