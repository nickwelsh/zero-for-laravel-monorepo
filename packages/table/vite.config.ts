import { defineConfig } from 'vite-plus';

export default defineConfig({
    pack: {
        entry: { index: 'src/index.ts', 'react/index': 'src/react/index.tsx' },
        format: ['esm'],
        dts: true,
        clean: true,
        deps: {
            neverBundle: [
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
