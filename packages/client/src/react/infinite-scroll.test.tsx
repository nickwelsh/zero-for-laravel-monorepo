// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { createRef, StrictMode, type ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import { InfiniteScroll, type InfiniteScrollRef } from './infinite-scroll';
import { NamedScroll, type ScrollHandle } from './scroll-context';

function page() {
    return {
        identity: 'feed',
        firstPage: 3,
        lastPage: 3,
        size: 2,
        viewedPage: 3,
        count: 0,
        complete: true,
        hasPrevious: true,
        hasNext: true,
        error: undefined,
        fetch: vi.fn(),
        view: vi.fn(),
    };
}

function mount(handle: ScrollHandle | null, children: ReactNode) {
    const binding = (state: ScrollHandle | null, content: ReactNode) => (
        <NamedScroll name="feed" handle={state}>
            {content}
        </NamedScroll>
    );
    const view = render(binding(handle, children));
    return (state: ScrollHandle | null, content = children) => view.rerender(binding(state, content));
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it('serializes slot and ref fetches until the requested range, not an old or cached range, completes', () => {
    const scroll = page();
    const ref = createRef<InfiniteScrollRef>();
    const feed = () => (
        <InfiniteScroll
            ref={ref}
            data="feed"
            as="nav"
            aria-label="feed"
            manual
            preserveUrl
            previous={({ fetch, manualMode }) => manualMode && <button onClick={() => fetch()}>Older</button>}
            loading="Loading next"
        >
            {({ loadingPrevious, loadingNext }) => (
                <p>{loadingPrevious ? 'previous' : loadingNext ? 'next' : 'idle'}</p>
            )}
        </InfiniteScroll>
    );
    const update = mount(scroll, feed());
    fireEvent.click(screen.getByText('Older'));
    act(() => ref.current!.fetchNext());
    expect(scroll.fetch.mock.calls).toEqual([['previous', true]]);
    expect(screen.getByRole('navigation', { name: 'feed' }).textContent).toBe('previous');
    update({ ...scroll, firstPage: 2, complete: false });
    expect(screen.getByText('previous')).toBeDefined();
    update({ ...scroll, firstPage: 2, count: 1 });
    expect(screen.getByText('idle')).toBeDefined();
    act(() => ref.current!.fetchNext());
    expect(screen.getByText('Loading next')).toBeDefined();
    expect(scroll.fetch).toHaveBeenLastCalledWith('next', true);
});

function observeIntersections() {
    const instances: Observer[] = [];
    class Observer {
        readonly targets = new Set<Element>();
        constructor(
            readonly notify: (entries: { target: Element; isIntersecting: boolean }[]) => void,
            readonly options?: IntersectionObserverInit,
        ) {
            instances.push(this);
        }
        observe = (el: Element) => this.targets.add(el);
        unobserve = (el: Element) => this.targets.delete(el);
        disconnect = () => this.targets.clear();
    }
    vi.stubGlobal('IntersectionObserver', Observer);
    return (el: Element) => instances.find((observer) => observer.targets.has(el));
}

it('observes custom trigger refs/selectors inside their scroller and gates automatic, not explicit, actions', () => {
    const observer = observeIntersections();
    const scroll = page();
    const ref = createRef<InfiniteScrollRef>();
    const end = createRef<HTMLButtonElement>();
    const feed = () => (
        <section style={{ overflowY: 'scroll' }}>
            <button id="above">Start</button>
            <InfiniteScroll
                data="feed"
                ref={ref}
                buffer={300}
                style={{ overflow: 'auto' }}
                onlyNext
                manualAfter={1}
                startElement="#above"
                endElement={end}
                itemsElement="#rows"
                previous="Hidden previous"
                next="Hidden next"
            >
                <ul id="rows">
                    <li>Item</li>
                </ul>
            </InfiniteScroll>
            <button ref={end}>End</button>
        </section>
    );
    const update = mount(scroll, feed());
    expect(screen.queryByText(/Hidden/)).toBeNull();
    expect(observer(screen.getByText('Start'))).toBeUndefined();
    const below = observer(end.current!)!;
    expect(below.options?.root).toBe(end.current!.parentElement);
    expect(below.options?.rootMargin).toBe('300px');
    act(() => below.notify([{ target: end.current!, isIntersecting: false }]));
    expect(scroll.fetch).not.toHaveBeenCalled();
    act(() => below.notify([{ target: end.current!, isIntersecting: true }]));
    expect(scroll.fetch.mock.calls).toEqual([['next', false]]);
    update({ ...scroll, lastPage: 4, count: 1 });
    expect(observer(end.current!)).toBeUndefined();
    act(() => below.notify([{ target: end.current!, isIntersecting: true }]));
    act(() => ref.current!.fetchPrevious());
    expect(scroll.fetch.mock.calls).toEqual([
        ['next', false],
        ['previous', false],
    ]);
});

it('restores the first surviving visible row in the scroller only after the prepend completes', () => {
    const scroll = page();
    const ref = createRef<InfiniteScrollRef>();
    const feed = (rows: string[]) => (
        <section data-testid="scroller" style={{ overflowY: 'auto', height: 100 }}>
            <InfiniteScroll data="feed" manual ref={ref}>
                {rows.map((row) => (
                    <div key={row}>{row}</div>
                ))}
            </InfiniteScroll>
        </section>
    );
    const update = mount(scroll, feed(['A', 'B']));
    const root = screen.getByTestId('scroller');
    root.scrollTop = 50;
    root.getBoundingClientRect = () => new DOMRect(0, 50, 200, 100);
    screen.getByText('A').getBoundingClientRect = () => new DOMRect(0, 55, 200, 10);
    let rowTop = 65;
    const row = screen.getByText('B');
    row.getBoundingClientRect = () => new DOMRect(0, rowTop, 200, 10);
    act(() => ref.current!.fetchPrevious());
    expect(scroll.fetch).toHaveBeenCalledWith('previous', false, { ordinal: 4, offset: 5, reverse: false });
    rowTop += 20;
    update({ ...scroll, firstPage: 2, complete: false }, feed(['Y', 'Z', 'B']));
    expect(root.scrollTop).toBe(50);
    rowTop += 10;
    update({ ...scroll, firstPage: 2, count: 1 }, feed(['Y', 'Z', 'B']));
    expect(root.scrollTop).toBe(80);
});

it('reports live row positions with the current URL policy, including when preservation is switched off', async () => {
    vi.useFakeTimers();
    const observer = observeIntersections();
    const scroll = { ...page(), firstPage: 2, viewedPage: 2 };
    const feed = (rows: string[], preserveUrl = false) => (
        <InfiniteScroll data="feed" manual preserveUrl={preserveUrl}>
            {rows.map((row) => (
                <p key={row}>{row}</p>
            ))}
        </InfiniteScroll>
    );
    const update = mount(scroll, feed(['A', 'B', 'C', 'D']));
    const rows = screen.getAllByRole('paragraph');
    const watcher = observer(rows[0]!)!;
    act(() => watcher.notify([rows[0]!, rows[2]!, rows[3]!].map((target) => ({ target, isIntersecting: true }))));
    act(() => {
        vi.advanceTimersByTime(300);
    });
    expect(scroll.view.mock.calls).toEqual([[3, false]]);
    update({ ...scroll, viewedPage: 3 }, feed(['C', 'D', 'A', 'B']));
    await act(async () => {
        await vi.advanceTimersByTimeAsync(300);
    });
    expect(scroll.view.mock.calls).toEqual([
        [3, false],
        [2, false],
    ]);
    act(() => watcher.notify([{ target: rows[2]!, isIntersecting: false }]));
    update({ ...scroll, viewedPage: 3 }, feed(['C', 'D', 'A', 'B'], true));
    act(() => {
        vi.advanceTimersByTime(300);
    });
    expect(scroll.view).toHaveBeenLastCalledWith(2, true);
    update({ ...scroll, viewedPage: 2 }, feed(['C', 'D', 'A', 'B']));
    act(() => {
        vi.advanceTimersByTime(300);
    });
    expect(scroll.view).toHaveBeenLastCalledWith(2, false);
});

it.each([false, true])('waits for deferred prepend DOM without fighting a reader who scrolled (%s)', async (moved) => {
    const scroll = page();
    const ref = createRef<InfiniteScrollRef>();
    const feed = (rows: string[]) => (
        <section data-testid="scroller" style={{ overflowY: 'scroll' }}>
            <InfiniteScroll data="feed" manual ref={ref}>
                {rows.map((r) => (
                    <p key={r}>{r}</p>
                ))}
            </InfiniteScroll>
        </section>
    );
    const update = mount(scroll, feed(['A']));
    const root = screen.getByTestId('scroller');
    root.getBoundingClientRect = () => new DOMRect(0, 0, 100, 100);
    let top = 40;
    screen.getByText('A').getBoundingClientRect = () => new DOMRect(0, top, 100, 10);
    act(() => ref.current!.fetchPrevious());
    const complete = { ...scroll, firstPage: 2, count: 1 };
    update(complete, feed(['A']));
    if (moved) {
        fireEvent.wheel(root, { deltaY: 10 });
        root.scrollTop = 10;
        fireEvent.scroll(root);
    }
    top += 30;
    await act(async () => update(complete, feed(['Y', 'Z', 'A'])));
    expect(root.scrollTop).toBe(moved ? 10 : 30);
});

it('reattaches automatic sentinels when the existing ancestor becomes a scroller', async () => {
    const observer = observeIntersections();
    mount(
        page(),
        <section data-testid="root">
            <InfiniteScroll data="feed" next="Next" />
        </section>,
    );
    const sentinel = screen.getByText('Next');
    expect(observer(sentinel)?.options?.root).toBe(null);
    const root = screen.getByTestId('root');
    await act(async () => {
        root.style.overflowY = 'scroll';
    });
    expect(observer(sentinel)?.options?.root).toBe(root);
});

it('recovers an interrupted prepend by ordinal after a guarded remount and deferred reverse rows', async () => {
    const scroll = page();
    const ref = createRef<InfiniteScrollRef>();
    const resumed = { ...scroll, firstPage: 2, restoreAnchor: { ordinal: 4, offset: 12, reverse: true } };
    const feed = (rows: string[], autoScroll = true) => (
        <StrictMode>
            <section data-testid="chat" style={{ overflowY: 'scroll' }}>
                <InfiniteScroll ref={ref} data="feed" manual reverse autoScroll={autoScroll}>
                    {rows.map((r) => (
                        <p
                            key={r}
                            ref={(el) => {
                                if (el) el.getBoundingClientRect = () => new DOMRect(0, r === 'E' ? 82 : 40, 100, 10);
                            }}
                        >
                            {r}
                        </p>
                    ))}
                </InfiniteScroll>
            </section>
        </StrictMode>
    );
    const update = mount(scroll, feed(['F', 'E'], false));
    const original = screen.getByTestId('chat');
    original.getBoundingClientRect = () => new DOMRect(0, 20, 100, 100);
    screen.getByText('F').getBoundingClientRect = () => new DOMRect(0, -20, 100, 10);
    screen.getByText('E').getBoundingClientRect = () => new DOMRect(0, 32, 100, 10);
    act(() => ref.current!.fetchPrevious());
    expect(scroll.fetch).toHaveBeenCalledWith('previous', false, resumed.restoreAnchor);
    update(null, <p>Checking access</p>);
    expect(original.isConnected).toBe(false);
    update({ ...resumed, complete: false }, feed([]));
    const root = screen.getByTestId('chat');
    const bottom = vi.fn();
    root.scrollTo = bottom;
    root.getBoundingClientRect = () => new DOMRect(0, 20, 100, 100);
    // The adapter is free to clear its numeric marker when the range completes.
    update({ ...scroll, firstPage: 2 }, feed([]));
    await act(async () => update({ ...scroll, firstPage: 2 }, feed(['F', 'E', 'D', 'C'])));
    expect(root.scrollTop).toBe(50);
    await act(async () => update({ ...scroll, firstPage: 2 }, feed(['G', 'F', 'E', 'D', 'C'])));
    expect(root.scrollTop).toBe(50);
    expect(bottom).not.toHaveBeenCalled();
});

it('diagnoses sentinels outside the items scroller rather than registering unreachable targets', () => {
    observeIntersections();
    expect(() =>
        mount(
            page(),
            <InfiniteScroll data="feed" style={{ overflowY: 'scroll' }} itemsElement="#rows">
                <ul id="rows">
                    <li>A</li>
                </ul>
            </InfiniteScroll>,
        ),
    ).toThrow(/startElement|endElement/);
});

it('reverses the slots and scrolls to the bottom on the first live rows, not on every live update', async () => {
    const scroll = page();
    const feed = (rows: string[]) => (
        <section style={{ overflowY: 'scroll' }} data-testid="chat">
            <InfiniteScroll data="feed" reverse manual previous="Previous" next="Next">
                {rows.map((r) => (
                    <p key={r}>{r}</p>
                ))}
            </InfiniteScroll>
        </section>
    );
    const update = mount(scroll, feed([]));
    const root = screen.getByTestId('chat');
    Object.defineProperty(root, 'scrollHeight', { value: 400 });
    const scrollTo = vi.fn();
    root.scrollTo = scrollTo;
    await act(async () => update(scroll, feed(['A', 'B'])));
    expect(root.textContent).toBe('NextABPrevious');
    expect(scrollTo).toHaveBeenCalledWith({ top: 400, behavior: 'instant' });
    await act(async () => update(scroll, feed(['C', 'A', 'B'])));
    expect(scrollTo).toHaveBeenCalledTimes(1);
});

it('drops loading on error and invalidates old fetches when the binding changes or becomes pending', () => {
    const scroll = page();
    const ref = createRef<InfiniteScrollRef>();
    const update = mount(null, <InfiniteScroll data="feed" manual ref={ref} loading="waiting" />);
    expect(ref.current!.hasPrevious()).toBe(false);
    update(scroll);
    const stale = ref.current!;
    act(() => stale.fetchPrevious());
    expect(screen.getByText('waiting')).toBeDefined();
    update({ ...scroll, error: new Error('Not available') });
    expect(screen.queryByText('waiting')).toBeNull();
    update({ ...scroll, identity: 'new filter' });
    act(() => stale.fetchNext());
    expect(scroll.fetch).toHaveBeenCalledTimes(1);
    act(() => ref.current!.fetchNext());
    update(null);
    expect(ref.current!.hasNext()).toBe(false);
    expect(screen.queryByText('waiting')).toBeNull();
    expect(scroll.fetch).toHaveBeenCalledTimes(2);
});

it('leaves the next action available when a superseded handle declines a fetch', () => {
    const scroll = page();
    scroll.fetch.mockReturnValueOnce(false);
    const ref = createRef<InfiniteScrollRef>();
    mount(scroll, <InfiniteScroll data="feed" manual ref={ref} loading="waiting" />);
    act(() => ref.current!.fetchNext());
    expect(screen.queryByText('waiting')).toBeNull();
    act(() => ref.current!.fetchNext());
    expect(scroll.fetch).toHaveBeenCalledTimes(2);
    expect(screen.getByText('waiting')).toBeDefined();
});

it('uses the latest URL policy when a caller keeps a fetch callback across renders', () => {
    const scroll = page();
    const ref = createRef<InfiniteScrollRef>();
    const update = mount(scroll, <InfiniteScroll data="feed" ref={ref} manual />);
    const fetchNext = ref.current!.fetchNext;
    update(scroll, <InfiniteScroll data="feed" ref={ref} manual preserveUrl />);
    act(() => fetchNext());
    expect(scroll.fetch).toHaveBeenCalledWith('next', true);
});

it('maps reverse DOM order back to query positions when the last page is short', () => {
    vi.useFakeTimers();
    const observer = observeIntersections();
    const scroll = { ...page(), firstPage: 2, viewedPage: 2 };
    mount(
        scroll,
        <InfiniteScroll data="feed" reverse autoScroll={false} manual>
            <p>C</p>
            <p>B</p>
            <p>A</p>
        </InfiniteScroll>,
    );
    const row = screen.getByText('C');
    act(() => observer(row)!.notify([{ target: row, isIntersecting: true }]));
    act(() => {
        vi.advanceTimersByTime(300);
    });
    expect(scroll.view.mock.calls).toEqual([[3, false]]);
});

it('rejects Inertia request options in JSX and imperative fetch calls', () => {
    const scroll = page();
    const ref = createRef<InfiniteScrollRef>();
    const update = mount(scroll, <InfiniteScroll data="feed" manual ref={ref} />);
    // @ts-expect-error Zero window expansion does not accept reload options.
    expect(() => ref.current!.fetchPrevious({ headers: {} })).toThrow(/reload options/);
    // @ts-expect-error Zero window expansion does not accept reload options.
    expect(() => ref.current!.fetchNext({ only: ['feed'] })).toThrow(/reload options/);
    // @ts-expect-error There is no Inertia request to configure.
    expect(() => update(scroll, <InfiniteScroll data="feed" params={{}} />)).toThrow(/params/);
    expect(scroll.fetch).not.toHaveBeenCalled();
});
