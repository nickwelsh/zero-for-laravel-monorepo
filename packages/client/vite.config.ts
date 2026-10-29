import { defineConfig } from 'vite-plus';

export default defineConfig({
    pack: {
        entry: {
            index: 'src/index.ts',
            'react/index': 'src/react/index.ts',
            'search/index': 'src/search/index.ts',
            'react/search': 'src/react/search.tsx',
        },
        format: ['esm'],
        dts: true,
        clean: true,
        deps: {
            neverBundle: [
                '@rocicorp/zero',
                '@rocicorp/zero/react',
                'react',
                'react/jsx-runtime',
                'react-instantsearch',
                'typesense-instantsearch-adapter',
            ],
        },
        outExtensions: () => ({ js: '.js', dts: '.d.ts' }),
    },
});
