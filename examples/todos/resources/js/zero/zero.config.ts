import type { ProviderOptions } from '@nickwelsh/zero-for-laravel/react';

const origin = import.meta.env.VITE_APP_URL;
export const options = {
    // A relative URL lets orb builds follow their portal origin without rebuilding.
    cacheURL: new URL(import.meta.env.VITE_ZERO_CACHE_URL, globalThis.location?.href ?? origin).href,
    queryURL: `${origin}/zero/query`,
    mutateURL: `${origin}/zero/mutate`,
} satisfies ProviderOptions;
