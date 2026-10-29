// @vitest-environment jsdom
import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { useSearchRefresh } from './use-search-refresh';

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('completed-index search refresh', () => {
    it('refreshes for changed revisions and focus, pauses hidden tabs, and aborts on unmount', async () => {
        vi.useFakeTimers();
        const hidden = vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
        const fetch = vi
            .fn<typeof globalThis.fetch>()
            .mockResolvedValueOnce(Response.json({ revision: 'first' }))
            .mockResolvedValueOnce(Response.json({ revision: 'first' }))
            .mockResolvedValue(Response.json({ revision: 'second' }));
        vi.stubGlobal('fetch', fetch);
        const refresh = vi.fn();
        const { unmount } = renderHook(() => useSearchRefresh('/search/revision', 'records', refresh));
        await act(async () => {
            await Promise.resolve();
        });
        expect(refresh).toHaveBeenCalledTimes(1);
        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
        expect(refresh).toHaveBeenCalledTimes(1);
        hidden.mockReturnValue(true);
        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
        expect(fetch).toHaveBeenCalledTimes(2);
        hidden.mockReturnValue(false);
        await act(async () => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
        expect(refresh).toHaveBeenCalledTimes(2);
        await act(async () => {
            globalThis.dispatchEvent(new Event('focus'));
        });
        expect(refresh).toHaveBeenCalledTimes(3);
        const signal = fetch.mock.calls[0]?.[1]?.signal;
        unmount();
        expect(signal?.aborted).toBe(true);
        await act(async () => {
            await vi.advanceTimersByTimeAsync(10000);
        });
        expect(fetch).toHaveBeenCalledTimes(4);
    });

    it('does not refresh from an obsolete index or fail the UI when polling is unavailable', async () => {
        vi.useFakeTimers();
        vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
        let resolve!: (value: Response) => void;
        const pending = new Promise<Response>((complete) => {
            resolve = complete;
        });
        const fetch = vi
            .fn<typeof globalThis.fetch>()
            .mockReturnValueOnce(pending)
            .mockRejectedValue(new Error('Offline'));
        vi.stubGlobal('fetch', fetch);
        const refresh = vi.fn();
        const { rerender } = renderHook(({ index }) => useSearchRefresh('/search/revision', index, refresh), {
            initialProps: { index: 'old' },
        });
        rerender({ index: 'new' });
        await act(async () => {
            resolve(Response.json({ revision: 'old' }));
        });
        expect(refresh).not.toHaveBeenCalled();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
        expect(fetch).toHaveBeenCalledTimes(3);
        expect(refresh).not.toHaveBeenCalled();
    });
});
